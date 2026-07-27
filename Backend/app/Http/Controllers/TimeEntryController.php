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
        $details['supervisor_override'] = in_array(auth()->user()->role, ['admin', 'supervisor'], true);

        try {
            $result = $this->clockService->processPunch(
                $user,
                $request->type,
                $request->time, // Solo para simulador, en prod usar nulo
                $details
            );

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
     * Evidencia fotográfica de comedor (estados #17/#18b) — recibe la foto en base64,
     * la guarda en disco y persiste la referencia.
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

        $filename = "meal_{$validated['type']}_{$user->id}_" . now()->format('YmdHis') . '_' . \Illuminate\Support\Str::random(6) . ".{$extension}";
        $relativeDir = "uploads/meal-evidence/{$tenantId}";
        $destinationPath = public_path($relativeDir);
        if (!file_exists($destinationPath)) {
            mkdir($destinationPath, 0755, true);
        }
        $fullPath = $destinationPath . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($fullPath, $decoded);

        $url = "/{$relativeDir}/{$filename}";

        \App\Models\MealPhotoEvidence::create([
            'tenant_id' => $tenantId,
            'employee_id' => $user->id,
            'date' => $validated['date'],
            'type' => $validated['type'],
            'url' => $url,
            'path' => $fullPath,
        ]);

        return response()->json(['success' => true, 'url' => $url]);
    }
}
