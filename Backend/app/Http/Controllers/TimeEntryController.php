<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Services\ClockService;
use App\Services\OfflineSignatureService;
use App\Models\User;
use App\Events\MonitorUpdated;

/**
 * RECONCILIACIÓN merge/reloj-v2 (F3): punch endurecido de la línea del Reloj (tenant null
 * explícito, scrub de flags reservados, override por ROL) + endpoints de la línea §1–§42
 * (offline-secret HMAC, contingencia, evidencia de comedor). El batch offline vive en
 * PunchBatchController (protocolo unificado).
 */
class TimeEntryController extends Controller
{
    protected $clockService;
    protected $offlineSignatureService;

    public function __construct(ClockService $clockService, OfflineSignatureService $offlineSignatureService)
    {
        $this->clockService = $clockService;
        $this->offlineSignatureService = $offlineSignatureService;
    }

    public function punch(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'type' => ['required', 'string', Rule::in(ClockService::PUNCH_TYPES)],
            'time' => 'nullable|string',
            'details' => 'nullable|array'
        ]);

        // §59: se resuelve SIN el scope global y se compara el tenant de forma
        // explícita y casteada — no se depende del TenantScope (que puede dar null y
        // reventar, o ser bypasseado por platform_admin/withoutGlobalScopes en otro punto).
        $user = User::withoutGlobalScopes()->find($request->user_id);
        $authTenantId = auth()->user()->tenant_id;

        // El destino debe pertenecer al tenant del emisor autenticado; si no, es una
        // inyección de ponche cross-tenant. Más estricto que el patrón de sync (R4): un emisor
        // con tenant_id null (p.ej. un platform_admin) NO puede fichar por nadie — el fallback
        // `?? 1` lo trataría como tenant 1 y podría inyectar ponches a ese tenant real.
        // (Converge con §59 del jefe, que además loggea el intento.)
        if ($authTenantId === null || !$user || (int) $user->tenant_id !== (int) $authTenantId) {
            \App\Helpers\SecurityLogger::log(
                'tenant_isolation_violation',
                "Intento de fichaje sobre user_id={$request->user_id} (tenant " . ($user->tenant_id ?? 'null') . ") desde tenant " . ($authTenantId ?? 'null'),
                $authTenantId,
                auth()->id()
            );
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado o no pertenece a su empresa.'
            ], 403);
        }

        // Override server-side del bloqueo de Retardo Extremo: solo un admin/supervisor
        // autenticado puede saltarlo. Se SOBRESCRIBE cualquier valor mandado por el cliente.
        $details = $request->details ?? [];
        // `sandbox_bypass` salta el IP-lock de sucursal en ClockService; escape SÓLO para tests.
        unset($details['sandbox_bypass']);
        // R88: `via_kiosk` sólo lo puede marcar el KioskController (exime el checklist de cierre).
        unset($details['via_kiosk']);

        // BORRADO ARBITRARIO DE ARCHIVOS (2026-08-08). `details.photo_url` (§67) se guardaba
        // TAL CUAL como lo mandara el cliente, y `clock-photos:purge` —que corre solo cada día
        // a las 03:15— hacía `@unlink(public_path(ltrim($photo_url, '/')))`. Con
        // `photo_url = "../.env"` eso resuelve a /var/www/.env: cualquier colaborador con
        // sesión podía marcar un fichaje y dejar programado el borrado del .env del servidor
        // (o de un expediente del storage privado) para cuando venciera la retención.
        //
        // La regla de fondo: el cliente NUNCA nombra archivos del servidor. La foto tiene que
        // subirse por un endpoint que decida la ruta el backend (como `/clock/meal-photo`);
        // mientras §67 no tenga ese endpoint, aquí sólo se aceptan nombres seguros dentro de
        // la carpeta esperada, que es justo lo que produciría ese endpoint.
        if (isset($details['photo_url']) && !$this->esRutaDeFotoSegura($details['photo_url'])) {
            unset($details['photo_url']);
        }

        // El override de supervisor sólo aplica cuando un mando ficha a OTRA persona (kiosco,
        // corrección). Antes se concedía a todo ponche de un admin/supervisor, incluido el suyo:
        // el mando se autorizaba a sí mismo por el mero hecho de serlo, y así pasó un check_in con
        // 863 minutos de retardo sin que nadie lo autorizara (prueba del dueño, 2026-08-21).
        // Nadie se autoriza a sí mismo — misma regla que el PIN y que la aprobación remota.
        $esMando = in_array(auth()->user()->role, ['admin', 'supervisor'], true);
        $details['supervisor_override'] = $esMando && (int) $user->id !== (int) auth()->id();

        try {
            // Un usuario, un ponche a la vez. El guard de duplicados (R63) leía el último marcador
            // y luego insertaba; dos peticiones del mismo doble clic llegaban en el mismo segundo,
            // las dos leían "sin comida abierta" y las dos insertaban (visto en vivo: dos meal_start
            // a las 02:59:23). El candado de fila sobre el usuario serializa sus ponches: la segunda
            // espera a que la primera confirme y entonces sí ve el segmento abierto.
            $result = \DB::transaction(function () use ($user, $request, $details) {
                \DB::table('users')->where('id', $user->id)->lockForUpdate()->first();

                return $this->clockService->processPunch(
                    $user,
                    $request->type,
                    $request->time, // Solo para simulador, en prod usar nulo
                    $details
                );
            });

            if (isset($result['success']) && $result['success']) {
                event(new MonitorUpdated($user->tenant_id));
            }

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Salida Doble Llave (spec:53-55): un supervisor autoriza una salida que quedó en
     * 'pending_approval'. Tenant-scoped y gated por rol vía la ruta (admin/supervisor).
     */
    public function authorizeCheckout(Request $request, $id)
    {
        $tenantId = auth()->user()->tenant_id;

        $entry = \App\Models\TimeEntry::withoutGlobalScopes()->find($id);
        if ($tenantId === null || !$entry || (int) $entry->tenant_id !== (int) $tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'Salida no encontrada o no pertenece a su empresa.'
            ], 403);
        }

        if ($entry->type !== 'check_out' || $entry->check_out_status !== 'pending_approval') {
            return response()->json([
                'success' => false,
                'message' => 'La salida no está pendiente de autorización.'
            ], 422);
        }

        $entry->update(['check_out_status' => 'approved']);

        DB::table('audit_logs')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $entry->user_id,
            'date' => $entry->date,
            'type' => 'checkout_approved',
            'timestamp_str' => now()->format('Y-m-d H:i:s'),
            'reason' => 'Salida autorizada por ' . auth()->user()->name . '.',
            'punishment_amount' => 0,
            'details' => json_encode(['approved_by' => auth()->user()->id]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        event(new MonitorUpdated($entry->tenant_id));

        return response()->json([
            'success' => true,
            'message' => 'Salida autorizada.'
        ]);
    }

    /**
     * §67.C — Incidencias de fichaje para el monitor del supervisor: fichajes que se
     * aceptaron pero omitieron la foto por falla de cámara cuando era obligatoria. Visibles
     * como incidencia, no enterrados en audit_logs. Filtra por el tenant del usuario.
     * (Resync 3: método de la línea del jefe; convive con authorizeCheckout de la del Reloj.)
     */
    public function flaggedPunches(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $entries = \App\Models\TimeEntry::where('tenant_id', $tenantId)
            ->where('flagged_for_review', true)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(100)
            // r2-c: `details` incluido para que la bandeja pueda MOSTRAR el porqué (deriva_min,
            // hora_reclamada vs recibido_a) y no sólo que "algo" pasó.
            ->get([
                'id', 'user_id', 'employee_name_at_time', 'date', 'type', 'time',
                'verification_method', 'photo_skipped_reason', 'details',
            ]);

        // (2026-08-28, revisión externa r2-c) «flagged_for_review es bitácora, no control,
        // hasta que alguien lo SUME». La marca por fichaje ya existía; esto la convierte en
        // patrón: cuántas veces cayó cada persona en revisión en los últimos 90 días y en
        // cuántos días distintos. Un corte de red real marca a MEDIA sucursal un día; el que
        // se quita retardos "offline" se marca SOLO, muchos días — eso es lo que el supervisor
        // necesita distinguir y ninguna fila individual le decía.
        $reincidencia = \App\Models\TimeEntry::where('tenant_id', $tenantId)
            ->where('flagged_for_review', true)
            ->where('date', '>=', now()->subDays(90)->toDateString())
            ->selectRaw('user_id, MAX(employee_name_at_time) as nombre, COUNT(*) as veces, COUNT(DISTINCT date) as dias, MIN(date) as desde, MAX(date) as hasta')
            ->groupBy('user_id')
            ->orderByDesc('veces')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $entries->count(),
            'data' => $entries,
            'reincidencia' => $reincidencia,
        ]);
    }

    /**
     * Devuelve el secreto HMAC vigente del tenant del usuario autenticado,
     * usado por el frontend para firmar fichajes offline (offline_stamp).
     */
    public function offlineSecret(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'message' => 'El usuario autenticado no pertenece a ningún tenant.'
            ], 400);
        }

        $result = $this->offlineSignatureService->getOrCreateCurrentSecret($tenantId);

        return response()->json([
            'success' => true,
            'secret' => $result['secret'],
            'issued_at' => $result['issued_at']->toIso8601String(),
        ]);
    }

    /**
     * Declaración de Contingencia — ARBITRAJE F3, endpoint de DOBLE protocolo:
     *
     * a) Protocolo §1–§42 (reason es uno de no_power/no_internet/no_power_and_internet):
     *    crea/reutiliza una ContingencyDeclaration ABIERTA tenant-wide → protección INMEDIATA del
     *    retardo mientras dure la eventualidad (processPunch la consulta). Responde 200 con
     *    `contingency_id`.
     *
     * b) Protocolo línea del Reloj R83/R101 (reason texto libre >= 10 chars): crea/reabre la fila
     *    `contingency_days` PENDIENTE del día — el pago 100% de nómina queda detrás del gate humano
     *    (un admin la aprueba). `declared_at_client` viene de la cola offline y se CLAMPEA
     *    server-side (sólo hacia atrás, máx 48h). Responde 201 con `contingency`.
     *
     * Los dos sistemas no se contaminan: una declaración pendiente del protocolo (b) NO congela
     * retardos (eso exige aprobación), y una eventualidad (a) no fabrica filas de aprobación.
     */
    public function declareContingency(Request $request)
    {
        $enumReasons = ['no_power', 'no_internet', 'no_power_and_internet'];
        $reason = $request->input('reason');
        $isEnumProtocol = is_string($reason) && in_array($reason, $enumReasons, true);

        if ($isEnumProtocol) {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'reason' => ['required', 'string', Rule::in($enumReasons)],
                'declared_at' => 'nullable|date',
                'offline_stamp' => 'nullable|string',
            ]);

            $user = User::find($validated['user_id']);
            if (!auth()->user()->tenant_id || $user->tenant_id !== auth()->user()->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Acceso denegado: el usuario no pertenece a tu empresa.'
                ], 403);
            }

            try {
                $result = $this->clockService->declareContingency(
                    $validated['user_id'],
                    $validated['reason'],
                    $validated['declared_at'] ?? null
                );

                event(new MonitorUpdated($user->tenant_id));

                return response()->json($result);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 400);
            }
        }

        // Protocolo (b): delega en el flujo con gate humano de la línea del Reloj.
        return app(ContingencyController::class)->declare($request);
    }

    /**
     * ¿Es `$ruta` una referencia de foto de fichaje que pudo haber generado el servidor?
     *
     * Sólo se acepta la carpeta esperada y un nombre de archivo llano: nada de `..`, ni de
     * rutas absolutas, ni de barras invertidas de Windows. Cualquier otra cosa es un intento
     * de nombrar un archivo ajeno y se descarta (ver el comentario de `punch`).
     */
    private function esRutaDeFotoSegura($ruta): bool
    {
        if (!is_string($ruta) || $ruta === '' || strlen($ruta) > 255) {
            return false;
        }

        if (str_contains($ruta, '..') || str_contains($ruta, '\\') || str_contains($ruta, "\0")) {
            return false;
        }

        return (bool) preg_match('#^/uploads/clock-photos/\d+/[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp)$#i', $ruta);
    }

    /**
     * Única puerta de salida de la evidencia de comedor (ronda 2026-08-08).
     *
     * La foto vive en disco PRIVADO; aquí se valida quién pregunta antes de entregarla:
     * el propio colaborador (es su cara) o un mando de SU MISMA empresa (para eso es la
     * evidencia). Antes no había puerta: el archivo estaba servido como estático público
     * y respondía 200 a cualquiera con la URL.
     */
    public function showMealEvidence(Request $request, string $uuid)
    {
        // El uuid llega de la URL y se usa en un LIKE: se valida el formato antes de tocar
        // la consulta, y el tenant siempre va explícito.
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            abort(404);
        }

        $user = auth()->user();

        $evidencia = \App\Models\MealPhotoEvidence::withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('path', 'like', "%/{$uuid}.%")
            ->first();

        if (!$evidencia) {
            abort(404);
        }

        $esSuya = (int) $evidencia->employee_id === (int) $user->id;
        $esMando = in_array($user->role, ['admin', 'supervisor'], true);

        if (!$esSuya && !$esMando) {
            abort(403, 'Esta evidencia no es tuya.');
        }

        if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($evidencia->path)) {
            abort(404);
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->response($evidencia->path);
    }

    /**
     * Evidencia fotográfica de comedor (estados #17/#18b) — recibe la foto en base64,
     * la guarda en disco PRIVADO y persiste la referencia.
     */
    public function uploadMealPhoto(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['meal_start', 'meal_end'])],
            'date' => 'required|date',
            'image' => 'required|string',
            'client_timestamp' => 'nullable|date',
        ]);

        if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $validated['image'], $matches)) {
            return response()->json(['success' => false, 'message' => 'Formato de imagen inválido.'], 422);
        }

        $extension = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
        if (!in_array($extension, ['jpg', 'png', 'webp'])) {
            return response()->json(['success' => false, 'message' => 'Formato de imagen no soportado (usa jpg, png o webp).'], 422);
        }

        $decoded = base64_decode($matches[2], true);
        if ($decoded === false) {
            return response()->json(['success' => false, 'message' => 'No se pudo decodificar la imagen.'], 422);
        }

        // Límite defensivo — el frontend ya comprime a ~200KB, esto solo evita abuso.
        if (strlen($decoded) > 2 * 1024 * 1024) {
            return response()->json(['success' => false, 'message' => 'La imagen supera el tamaño máximo permitido (2MB).'], 422);
        }

        $user = auth()->user();
        $tenantId = $user->tenant_id ?? 1;

        // STORAGE PRIVADO (ronda 2026-08-08). Antes esto iba a `public_path()`, o sea que la
        // foto de una persona quedaba servida como archivo estático SIN autenticación: se
        // comprobó en el servidor que respondía 200 image/jpeg a cualquiera que pidiera la
        // URL. Encima el nombre era adivinable (tipo + user_id + fecha + 6 al azar) y la
        // purga a 90 días borraba la FILA dejando el archivo público para siempre.
        //
        // Mismo patrón que el Archivo Digital: nombre uuid en disco privado y una sola
        // puerta de salida, el endpoint autenticado `meal-evidence/{id}`.
        // El uuid identifica la evidencia hacia afuera: no es adivinable como lo era
        // "meal_meal_start_{user_id}_{fecha}", y se conoce antes de insertar.
        $uuid = (string) \Illuminate\Support\Str::uuid();
        $relativePath = "meal-evidence/{$tenantId}/{$uuid}.{$extension}";
        \Illuminate\Support\Facades\Storage::disk('local')->put($relativePath, $decoded);

        $url = "/api/v1/clock/meal-evidence/{$uuid}";

        \App\Models\MealPhotoEvidence::create([
            'tenant_id' => $tenantId,
            'employee_id' => $user->id,
            'date' => $validated['date'],
            'type' => $validated['type'],
            // `url` deja de ser una ruta servible y pasa a ser el endpoint que valida quién
            // pregunta; `path` es la ruta dentro del disco privado.
            'url' => $url,
            'path' => $relativePath,
        ]);

        return response()->json(['success' => true, 'url' => $url]);
    }
}
