<?php

namespace App\Http\Controllers;

use App\Events\MonitorUpdated;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sincronización de la cola offline en LOTE (Ronda 84, Fase 2 / T2.1 del plan v2).
 *
 * Antes la cola (offlineDb.ts, R29) se drenaba 1×1 vía /clock/punch: sin atomicidad (un fallo a mitad
 * dejaba medio lote sincronizado), sin idempotencia (un re-envío duplicaba) y perdiendo el momento
 * offline real (en prod el `time` del cliente se ignora → el ponche quedaba a la hora de SYNC).
 *
 * Aquí el lote se procesa en UNA `DB::transaction` (inmutabilidad histórica: o se graba entero o
 * nada), idempotente por `client_stamp`, registrando cada ponche en su MOMENTO offline (`occurred_at`).
 * Cada ponche va en su propio SAVEPOINT (`DB::transaction` anidado): un rechazo (fecha futura, otro
 * tenant, geocerca…) revierte SÓLO ese ponche y el resto del lote sigue — en Postgres una excepción
 * SIN savepoint aborta la transacción completa (lección R51).
 */
class PunchBatchController extends Controller
{
    /** Tope del lote: una cola offline real no acumula miles; acota abuso/DoS. */
    private const MAX_BATCH = 200;

    /**
     * Ventana de antigüedad: un ponche offline no puede ser del futuro ni de hace demasiado. 7 días
     * cubre una desconexión real (rara vez mayor) y ACOTA el backdating: la asistencia offline confía
     * en la hora del cliente por naturaleza (y el GPS de la geocerca va cacheado, también del cliente),
     * así que cuanto más corta la ventana, menor la superficie de "fingir asistencia" (review R84).
     */
    private const MAX_AGE_DAYS = 7;
    private const FUTURE_SKEW_MINUTES = 5; // tolera un pequeño desfase de reloj del dispositivo

    protected ClockService $clockService;

    public function __construct(ClockService $clockService)
    {
        $this->clockService = $clockService;
    }

    public function batch(Request $request)
    {
        // Sólo lo ESTRUCTURAL a nivel request (422 aborta todo el lote). `type` y `occurred_at` se
        // validan POR ÍTEM en el loop: un ítem legacy/corrupto (tipo obsoleto, fecha no parseable) NO
        // debe hacer 422 de TODO el lote y volverse una píldora venenosa que atasca la cola para
        // siempre (review R84, misma clase que R82). client_stamp sí es requisito estructural: sin él
        // no hay idempotencia ni forma de mapear el resultado en el cliente.
        $request->validate([
            'punches' => ['required', 'array', 'min:1', 'max:' . self::MAX_BATCH],
            'punches.*.client_stamp' => ['required', 'string', 'max:100'],
            'punches.*.details' => ['nullable', 'array'],
            'punches.*.user_id' => ['nullable', 'integer'],
        ]);

        $actor = Auth::user();
        $tenantId = $actor->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        // Orden cronológico por momento offline (defensivo: un occurred_at no parseable va al final y
        // el loop lo rechazará). El guard de check_in duplicado (R63) y el pareo de comidas dependen
        // de ver los ponches previos del lote en el orden correcto.
        $punches = collect($request->input('punches'))
            ->sortBy(fn ($p) => $this->parseOrNull($p['occurred_at'] ?? null)?->getTimestamp() ?? PHP_INT_MAX)
            ->values();

        $now = Carbon::now();
        $results = [];
        $anyRecorded = false;

        DB::transaction(function () use ($punches, $tenantId, $actor, $now, &$results, &$anyRecorded) {
            foreach ($punches as $p) {
                $stamp = $p['client_stamp'];

                // Idempotencia POR USUARIO (review R84): la firma de un empleado no puede sombrear la
                // de otro. Lectura simple (no aborta la tx); dentro de la tx externa ve también los
                // ponches ya grabados en este mismo lote.
                $yaExiste = TimeEntry::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $actor->id)
                    ->where('client_stamp', $stamp)
                    ->exists();
                if ($yaExiste) {
                    $results[] = ['client_stamp' => $stamp, 'status' => 'duplicate'];
                    continue;
                }

                try {
                    // SAVEPOINT por ponche: un rechazo revierte sólo éste, no el lote.
                    $recorded = DB::transaction(function () use ($p, $tenantId, $actor, $now, $stamp) {
                        // Validación POR ÍTEM (no request-level → un ítem malo no tumba el lote):
                        if (!in_array($p['type'] ?? null, ClockService::PUNCH_TYPES, true)) {
                            throw new \RuntimeException('Tipo de ponche inválido.');
                        }
                        $occurredAt = $this->parseOrNull($p['occurred_at'] ?? null);
                        if (!$occurredAt) {
                            throw new \RuntimeException('Fecha del ponche inválida.');
                        }

                        // B1 (review R84): sólo se sincronizan los ponches del PROPIO emisor. Aceptar un
                        // user_id ajeno convertía el batch en un vector de "fichar por un colega" a
                        // escala (200 ponches backdated con GPS falso para cualquiera del tenant). La
                        // cola offline del reloj personal es SIEMPRE del usuario logueado; el kiosko usa
                        // otro flujo (PIN). Va ANTES de la firma para dar el mensaje preciso (la firma se
                        // recomputa con actor->id; un ponche de otro usuario daría "firma inválida" en
                        // vez del motivo real — review R85).
                        $targetId = $p['user_id'] ?? $actor->id;
                        if ((int) $targetId !== (int) $actor->id) {
                            throw new \RuntimeException('Sólo puedes sincronizar tus propios ponches.');
                        }
                        $target = User::withoutGlobalScopes()->find($actor->id);
                        if (!$target || (int) $target->tenant_id !== (int) $tenantId) {
                            throw new \RuntimeException('Usuario no pertenece a su empresa.');
                        }

                        // R85: verificación de la firma offline_stamp. Se comprueba SÓLO si el stamp
                        // tiene formato de hash SHA-256 (64 hex); los legacy/fallback (UUID, sin firma)
                        // se aceptan sin verificar (siguen sirviendo de idempotencia). La firma se
                        // recomputa sobre el occurred_at TAL COMO SE MANDÓ y el GPS de details, con la
                        // misma canonicalización del FE. Un desajuste = corrupción o campo alterado.
                        $gps = is_array($p['details'] ?? null) ? ($p['details']['gps'] ?? null) : null;
                        if (preg_match('/\A[a-f0-9]{64}\z/', (string) $stamp)) {
                            $expected = self::stampFor($actor->id, $p['type'], (string) $p['occurred_at'], $gps);
                            if (!hash_equals($expected, (string) $stamp)) {
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

                        // Detalles del cliente: se scrubbea el bypass del IP-lock (igual que /clock/punch)
                        // y se fija el override por ROL del emisor (no por lo que mande el cliente).
                        $details = is_array($p['details'] ?? null) ? $p['details'] : [];
                        unset($details['sandbox_bypass']);
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

                    // El guard R63 devuelve `duplicate:true` sin crear fila (2º check_in con turno abierto).
                    if (isset($recorded['duplicate']) && $recorded['duplicate']) {
                        $results[] = ['client_stamp' => $stamp, 'status' => 'duplicate'];
                    } else {
                        $results[] = ['client_stamp' => $stamp, 'status' => 'recorded'];
                        $anyRecorded = true;
                    }
                } catch (UniqueConstraintViolationException $e) {
                    // Carrera: otra petición grabó el mismo stamp entre el exists() y el insert.
                    $results[] = ['client_stamp' => $stamp, 'status' => 'duplicate'];
                } catch (\Throwable $e) {
                    // Rechazo permanente (fecha inválida, otro tenant, geocerca, tienda cerrada…): el
                    // savepoint ya revirtió este ponche. Se reporta para que el cliente lo DESCARTE de
                    // la cola (no reintentar; el estado no cambiará) — mismo criterio que el 422 de R29.
                    $results[] = ['client_stamp' => $stamp, 'status' => 'rejected', 'message' => $e->getMessage()];
                }
            }
        });

        if ($anyRecorded) {
            event(new MonitorUpdated($tenantId));
        }

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }

    /**
     * Parseo defensivo de `occurred_at`: null si falta o no es parseable (el loop lo rechaza como
     * ítem inválido en vez de reventar). El FE lo manda como ISO-8601 UTC (`toISOString()`); un
     * cliente que mandara hora local naïve la vería interpretada en app.timezone (UTC) — contrato
     * documentado, no una vía de fraude (desplazar la fecha sólo perjudica a quien la manda).
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
     * Firma `offline_stamp` (R85): SHA-256 sobre los campos del ponche encolado. El FE la calcula al
     * ENCOLAR con la MISMA canonicalización (offlineDb.ts) y la manda como `client_stamp`; aquí se
     * recomputa y se compara para detectar CORRUPCIÓN (bit-rot en IndexedDB / transmisión) y para
     * ATAR la firma al contenido (no se puede reusar una firma para otro ponche).
     *
     * ⚠️ NO es anti-fraude: sin secreto en el cliente (bundle público), un atacante que altere un
     * campo puede recomputar el hash. El backdating de la hora/GPS offline sigue siendo inherente
     * (mitigado por ventana + geocerca + que el ponche queda marcado). Ver doc R84/R85.
     *
     * Canonical: `userId|type|occurredAtIso|latMicro,lngMicro` (o `...|nogps`). El GPS va en
     * MICRO-GRADOS ENTEROS (`round(x*1e6)`) para evitar el formateo frágil de floats entre JS y PHP
     * (verificado: mismo doble IEEE-754 → mismo entero → mismo hash).
     */
    public static function stampFor($userId, string $type, string $occurredAtIso, $gps): string
    {
        $gpsPart = 'nogps';
        // El guard exige NÚMEROS (no strings numéricos) para casar con el `typeof === 'number'` del FE
        // (offlineDb.ts); una lat/lng como string "19.43" daría 'nogps' en el FE pero coord en el BE.
        $latOk = isset($gps['latitude']) && (is_int($gps['latitude']) || is_float($gps['latitude']));
        $lngOk = isset($gps['longitude']) && (is_int($gps['longitude']) || is_float($gps['longitude']));
        if (is_array($gps) && $latOk && $lngOk) {
            // `floor(x + 0.5)` replica EXACTAMENTE JS Math.round (redondea el .5 hacia +∞); PHP round()
            // aleja de cero, divergiendo en coordenadas NEGATIVAS que caigan en un medio-entero exacto
            // → hashes distintos → rechazo de un ponche legítimo (review R85). México tiene lng < 0.
            $lat = (int) floor(((float) $gps['latitude']) * 1e6 + 0.5);
            $lng = (int) floor(((float) $gps['longitude']) * 1e6 + 0.5);
            $gpsPart = "{$lat},{$lng}";
        }
        $canonical = ((int) $userId) . '|' . $type . '|' . $occurredAtIso . '|' . $gpsPart;
        return hash('sha256', $canonical);
    }
}
