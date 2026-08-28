<?php

namespace App\Http\Controllers;

use App\Events\MonitorUpdated;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use App\Services\OfflineSignatureService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Sincronización de la cola offline en LOTE — PROTOCOLO UNIFICADO (merge F3).
 *
 * Une los dos protocolos de batch que traían las dos líneas:
 *  - Línea del Reloj (R84/R85): ítems con `client_stamp` (idempotencia) + `occurred_at` (momento
 *    offline real); firma de INTEGRIDAD sha-256 auto-computada cuando el stamp tiene forma de hash.
 *  - Línea §1–§42 (§16): ítems con `offline_stamp` = HMAC del SECRETO del tenant (anti-forja real,
 *    OfflineSignatureService) sobre "user|type|time|client_timestamp".
 *
 * Reglas comunes: una sola DB::transaction con SAVEPOINT por ponche (un rechazo no tumba el lote),
 * idempotencia por stamp, sólo ponches PROPIOS (B1: aceptar user_id ajeno convertía el batch en
 * "fichar por un colega" a escala), ventana de antigüedad anti-backdating, y el momento offline
 * REAL viaja a processPunch como `occurredAt` (inmutabilidad histórica LFT).
 */
class PunchBatchController extends Controller
{
    /** Tope del lote: una cola offline real no acumula miles; acota abuso/DoS. */
    private const MAX_BATCH = 200;

    /** Ventana de antigüedad (R84): acota el backdating inherente a la asistencia offline. */
    private const MAX_AGE_DAYS = 7;
    private const FUTURE_SKEW_MINUTES = 5; // tolera un pequeño desfase de reloj del dispositivo

    protected ClockService $clockService;
    protected OfflineSignatureService $offlineSignatureService;

    public function __construct(ClockService $clockService, OfflineSignatureService $offlineSignatureService)
    {
        $this->clockService = $clockService;
        $this->offlineSignatureService = $offlineSignatureService;
    }

    public function batch(Request $request)
    {
        // Sólo lo ESTRUCTURAL a nivel request (422 aborta todo el lote); `type`, las fechas y la
        // PRESENCIA de la credencial se validan POR ÍTEM en el loop (un ítem corrupto no debe
        // volverse píldora venenosa, R84). Cada ítem trae su credencial de protocolo: client_stamp
        // (Reloj) u offline_stamp (§16, con su client_timestamp).
        //
        // (2026-08-28 r2b) La credencial se valida aquí SÓLO como estructura (nullable + string):
        // `required_without` a nivel request tumbaba TODO el lote con 422 si un solo ítem llegaba
        // sin firma — un ítem legado o sin secreto en IndexedDB se volvía píldora venenosa y
        // congelaba la cola entera para siempre (los ponches buenos nunca llegaban y vencían a los
        // 7 días). La ausencia de credencial ahora rechaza SÓLO ese ítem, en el loop.
        $request->validate([
            'punches' => ['required', 'array', 'min:1', 'max:' . self::MAX_BATCH],
            'punches.*.client_stamp' => ['nullable', 'string', 'max:100'],
            'punches.*.offline_stamp' => ['nullable', 'string'],
            'punches.*.client_timestamp' => ['nullable', 'date'],
            'punches.*.details' => ['nullable', 'array'],
            'punches.*.gps' => ['nullable', 'array'],
            'punches.*.user_id' => ['nullable', 'integer'],
        ]);

        $actor = Auth::user();
        $tenantId = $actor->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        // Orden cronológico por momento offline conservando el índice original (el FE de la línea
        // §16 mapea resultados por `index`; el de la línea del Reloj por `client_stamp`).
        $indexed = [];
        foreach ($request->input('punches') as $i => $item) {
            $indexed[] = ['index' => $i, 'item' => $item];
        }
        usort($indexed, function ($a, $b) {
            $ta = $this->parseOrNull($a['item']['occurred_at'] ?? $a['item']['client_timestamp'] ?? null)?->getTimestamp() ?? PHP_INT_MAX;
            $tb = $this->parseOrNull($b['item']['occurred_at'] ?? $b['item']['client_timestamp'] ?? null)?->getTimestamp() ?? PHP_INT_MAX;
            return $ta <=> $tb;
        });

        $now = Carbon::now();
        $results = [];
        $anyRecorded = false;

        DB::transaction(function () use ($indexed, $tenantId, $actor, $now, &$results, &$anyRecorded) {
            foreach ($indexed as $wrapper) {
                $index = $wrapper['index'];
                $p = $wrapper['item'];

                // El stamp de idempotencia: el propio client_stamp, o el HMAC (único por ponche).
                $stamp = trim((string) ($p['client_stamp'] ?? $p['offline_stamp'] ?? ''));

                // (r2b) Sin credencial no hay idempotencia (todos los stampless colapsarían al
                // mismo '' y se pisarían) ni firma posible: se RECHAZA este ítem, no el lote. Es
                // el cierre de la píldora venenosa por el otro extremo.
                if ($stamp === '') {
                    $results[] = ['index' => $index, 'client_stamp' => null, 'status' => 'rejected', 'success' => false, 'reason' => 'missing_stamp'];
                    continue;
                }

                // HMAC (§16) sólo si trae offline_stamp NO vacío y NO client_stamp.
                $isHmacProtocol = !empty($p['offline_stamp']) && empty($p['client_stamp']);

                // Idempotencia POR USUARIO (review R84): re-enviar un lote no duplica.
                $yaExiste = TimeEntry::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $actor->id)
                    ->where('client_stamp', $stamp)
                    ->exists();
                if ($yaExiste) {
                    $results[] = ['index' => $index, 'client_stamp' => $stamp, 'status' => 'duplicate', 'success' => false, 'reason' => 'duplicate'];
                    continue;
                }

                // §16: verificación HMAC con el secreto del tenant ANTES de procesar. Un ítem con
                // firma inválida se excluye sin abortar el resto del lote.
                if ($isHmacProtocol) {
                    $msg = ($p['user_id'] ?? $actor->id) . '|' . ($p['type'] ?? '') . '|' . ($p['time'] ?? '') . '|' . ($p['client_timestamp'] ?? '');
                    if (!$this->offlineSignatureService->verifyStamp($tenantId, (string) $p['offline_stamp'], $msg)) {
                        $results[] = ['index' => $index, 'success' => false, 'reason' => 'invalid_signature', 'status' => 'rejected'];
                        continue;
                    }
                }

                try {
                    // SAVEPOINT por ponche: un rechazo revierte sólo éste, no el lote.
                    $recorded = DB::transaction(function () use ($p, $tenantId, $actor, $now, $stamp, $isHmacProtocol) {
                        // Validación POR ÍTEM:
                        if (!in_array($p['type'] ?? null, ClockService::PUNCH_TYPES, true)) {
                            throw new \RuntimeException('Tipo de ponche inválido.');
                        }
                        $occurredAt = $this->parseOrNull($p['occurred_at'] ?? $p['client_timestamp'] ?? null);
                        if (!$occurredAt) {
                            throw new \RuntimeException('Fecha del ponche inválida.');
                        }

                        // B1 (review R84): sólo se sincronizan los ponches del PROPIO emisor.
                        $targetId = $p['user_id'] ?? $actor->id;
                        if ((int) $targetId !== (int) $actor->id) {
                            throw new \RuntimeException('Sólo puedes sincronizar tus propios ponches.');
                        }
                        $target = User::withoutGlobalScopes()->find($actor->id);
                        if (!$target || (int) $target->tenant_id !== (int) $tenantId) {
                            throw new \RuntimeException('Usuario no pertenece a su empresa.');
                        }

                        // R85: firma de integridad del protocolo client_stamp — se comprueba SÓLO si
                        // el stamp tiene formato de hash SHA-256 (64 hex); los legacy/UUID se aceptan
                        // sin verificar (siguen sirviendo de idempotencia). El protocolo HMAC ya se
                        // verificó arriba con el secreto del tenant.
                        $gps = is_array($p['details'] ?? null) ? ($p['details']['gps'] ?? null) : null;
                        if (!$isHmacProtocol && preg_match('/\A[a-f0-9]{64}\z/', $stamp)) {
                            $expected = self::stampFor($actor->id, $p['type'], (string) $p['occurred_at'], $gps);
                            if (!hash_equals($expected, $stamp)) {
                                throw new \RuntimeException('Firma del ponche inválida (posible corrupción).');
                            }
                        }

                        // Ventana de antigüedad del momento offline (anti-backdating grosero).
                        if ($occurredAt->greaterThan($now->copy()->addMinutes(self::FUTURE_SKEW_MINUTES))) {
                            throw new \RuntimeException('El ponche no puede ser del futuro.');
                        }
                        if ($occurredAt->lessThan($now->copy()->subDays(self::MAX_AGE_DAYS))) {
                            throw new \RuntimeException('El ponche offline es demasiado antiguo.');
                        }

                        // Detalles del cliente: scrub del bypass + gps del protocolo §16 + override
                        // por ROL del emisor (no por lo que mande el cliente).
                        $details = is_array($p['details'] ?? null) ? $p['details'] : [];
                        unset($details['sandbox_bypass']);
                        // (r2b) Un ponche offline REAL nunca es del simulador: se quita is_simulator
                        // por higiene (el chokepoint de ClockService ya lo gatea por rol, esto cierra
                        // además el salto del candado de deriva por la vía del batch).
                        unset($details['is_simulator']);
                        if (isset($p['gps']) && is_array($p['gps'])) {
                            $details['gps'] = $p['gps'];
                        }
                        $details['supervisor_override'] = in_array($actor->role, ['admin', 'supervisor'], true);

                        return $this->clockService->processPunch(
                            $target,
                            $p['type'],
                            null,
                            $details,
                            $occurredAt->toIso8601String(),
                            $stamp
                        );
                    });

                    // El guard R63 devuelve `duplicate:true` sin crear fila.
                    if (isset($recorded['duplicate']) && $recorded['duplicate']) {
                        $results[] = ['index' => $index, 'client_stamp' => $stamp, 'status' => 'duplicate', 'success' => false, 'reason' => 'duplicate'];
                    } else {
                        $results[] = [
                            'index' => $index,
                            'client_stamp' => $stamp,
                            'status' => 'recorded',
                            'success' => true,
                            'entry_id' => $recorded['entry']->id ?? null,
                            'type' => $p['type'],
                        ];
                        $anyRecorded = true;
                    }
                } catch (UniqueConstraintViolationException $e) {
                    // Carrera: otra petición grabó el mismo stamp entre el exists() y el insert.
                    $results[] = ['index' => $index, 'client_stamp' => $stamp, 'status' => 'duplicate', 'success' => false, 'reason' => 'duplicate'];
                } catch (\Throwable $e) {
                    // Rechazo permanente: el savepoint ya revirtió este ponche. Se reporta para que
                    // el cliente lo DESCARTE de la cola (no reintentar).
                    $results[] = ['index' => $index, 'client_stamp' => $stamp, 'status' => 'rejected', 'success' => false, 'reason' => 'processing_error', 'message' => $e->getMessage()];
                }
            }
        });

        if ($anyRecorded) {
            event(new MonitorUpdated($tenantId));
        }

        // Orden de RESPUESTA = orden original del lote (contrato §16; el FE del Reloj mapea por
        // client_stamp así que le es indistinto).
        usort($results, fn ($a, $b) => $a['index'] <=> $b['index']);

        return response()->json([
            'success' => true,
            'processed' => count(array_filter($results, fn ($r) => $r['success'])),
            'rejected' => count(array_filter($results, fn ($r) => !$r['success'])),
            'results' => array_values($results),
        ]);
    }

    /**
     * Parseo defensivo del momento offline: null si falta o no es parseable (el loop lo rechaza
     * como ítem inválido en vez de reventar).
     */
    private function parseOrNull($value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Firma `client_stamp` de integridad (R85): SHA-256 sobre los campos del ponche encolado con la
     * MISMA canonicalización del FE (offlineDb.ts). Detecta CORRUPCIÓN y ata la firma al contenido.
     * ⚠️ NO es anti-fraude (sin secreto en el cliente); para anti-forja real está el protocolo HMAC.
     * Canonical: `userId|type|occurredAtIso|latMicro,lngMicro` (o `...|nogps`), GPS en micro-grados
     * ENTEROS con redondeo estilo JS Math.round (floor(x+0.5)) para paridad exacta JS↔PHP.
     */
    public static function stampFor($userId, string $type, string $occurredAtIso, $gps): string
    {
        $gpsPart = 'nogps';
        $latOk = isset($gps['latitude']) && (is_int($gps['latitude']) || is_float($gps['latitude']));
        $lngOk = isset($gps['longitude']) && (is_int($gps['longitude']) || is_float($gps['longitude']));
        if (is_array($gps) && $latOk && $lngOk) {
            $lat = (int) floor(((float) $gps['latitude']) * 1e6 + 0.5);
            $lng = (int) floor(((float) $gps['longitude']) * 1e6 + 0.5);
            $gpsPart = "{$lat},{$lng}";
        }
        $canonical = ((int) $userId) . '|' . $type . '|' . $occurredAtIso . '|' . $gpsPart;
        return hash('sha256', $canonical);
    }
}
