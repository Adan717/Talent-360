<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use App\Services\ClockService;
use App\Services\OfflineSignatureService;
use App\Models\User;
use App\Events\MonitorUpdated;

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
            'type'    => ['required', 'string', Rule::in(\App\Services\ClockService::ALLOWED_TYPES)],
            'time'    => 'nullable|string',
            'details' => 'nullable|array'
        ]);

        // §59: se resuelve SIN el scope global y se compara el tenant de forma
        // explícita y casteada — no se depende del TenantScope (que puede dar null y
        // reventar, o ser bypasseado por platform_admin/withoutGlobalScopes en otro punto).
        $user = User::withoutGlobalScopes()->find($request->user_id);
        $authTenantId = auth()->user()->tenant_id;

        if (!$user || (int) $user->tenant_id !== (int) $authTenantId) {
            \App\Helpers\SecurityLogger::log(
                'tenant_isolation_violation',
                "Intento de fichaje sobre user_id={$request->user_id} (tenant " . ($user->tenant_id ?? 'null') . ") desde tenant {$authTenantId}",
                $authTenantId,
                auth()->id()
            );
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado: el usuario no pertenece a tu empresa.'
            ], 403);
        }

        try {
            $result = $this->clockService->processPunch(
                $user,
                $request->type,
                $request->time, // Solo para simulador, en prod usar nulo
                $request->details ?? []
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
     * §67.C — Incidencias de fichaje para el monitor del supervisor: fichajes que se
     * aceptaron pero omitieron la foto por falla de cámara cuando era obligatoria. Visibles
     * como incidencia, no enterrados en audit_logs. Filtra por el tenant del usuario.
     */
    public function flaggedPunches(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $entries = \App\Models\TimeEntry::where('tenant_id', $tenantId)
            ->where('flagged_for_review', true)
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(100)
            ->get([
                'id', 'user_id', 'employee_name_at_time', 'date', 'type', 'time',
                'verification_method', 'photo_skipped_reason',
            ]);

        return response()->json([
            'success' => true,
            'count' => $entries->count(),
            'data' => $entries,
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
     * Sincroniza en una sola transacción la cola de fichajes acumulados offline.
     * Cada ítem trae su propio offline_stamp (HMAC firmado con el secreto del tenant);
     * los que no validan se excluyen sin abortar el resto del lote.
     */
    public function punchBatch(Request $request)
    {
        $request->validate([
            'punches' => 'required|array|min:1',
            'punches.*.user_id' => 'required|exists:users,id',
            'punches.*.type' => ['required', 'string', Rule::in(ClockService::ALLOWED_TYPES)],
            'punches.*.time' => 'nullable|string',
            'punches.*.details' => 'nullable|array',
            'punches.*.gps' => 'nullable|array',
            'punches.*.client_timestamp' => 'required|date',
            'punches.*.offline_stamp' => 'required|string',
        ]);

        $authTenantId = auth()->user()->tenant_id;
        $punches = $request->input('punches');

        // Protección anti-Tenant-Spoofing: ningún ítem del lote puede referenciar
        // un usuario de otro tenant (mismo criterio que punch()).
        $userIds = array_unique(array_column($punches, 'user_id'));
        // §59: resolver sin scope global y comparar tenant con casteo explícito.
        $users = User::withoutGlobalScopes()->whereIn('id', $userIds)->get()->keyBy('id');

        foreach ($userIds as $uid) {
            $user = $users->get($uid);
            if (!$user || (int) $user->tenant_id !== (int) $authTenantId) {
                \App\Helpers\SecurityLogger::log(
                    'tenant_isolation_violation',
                    "Lote de fichaje con user_id={$uid} de otro tenant, desde tenant {$authTenantId}",
                    $authTenantId,
                    auth()->id()
                );
                return response()->json([
                    'success' => false,
                    'message' => 'Acceso denegado: el lote incluye un usuario que no pertenece a tu empresa.'
                ], 403);
            }
        }

        // Conserva el índice original de cada ítem (el que el frontend usa para
        // borrar de IndexedDB) pero procesa en orden cronológico ascendente.
        $indexed = [];
        foreach ($punches as $i => $item) {
            $indexed[] = ['index' => $i, 'item' => $item];
        }
        usort($indexed, function ($a, $b) {
            return strtotime($a['item']['client_timestamp']) <=> strtotime($b['item']['client_timestamp']);
        });

        $results = [];
        $anySuccess = false;

        DB::transaction(function () use ($indexed, $users, $authTenantId, &$results, &$anySuccess) {
            foreach ($indexed as $entry) {
                $index = $entry['index'];
                $item = $entry['item'];
                $user = $users->get($item['user_id']);

                $message = "{$item['user_id']}|{$item['type']}|{$item['time']}|{$item['client_timestamp']}";
                $isValid = $this->offlineSignatureService->verifyStamp($authTenantId, $item['offline_stamp'], $message);

                if (!$isValid) {
                    $results[] = ['index' => $index, 'success' => false, 'reason' => 'invalid_signature'];
                    continue;
                }

                $details = $item['details'] ?? [];
                if (isset($item['gps'])) {
                    $details['gps'] = $item['gps'];
                }
                $details['offline_sync'] = true;

                try {
                    $punchResult = $this->clockService->processPunch(
                        $user,
                        $item['type'],
                        $item['time'] ?? null,
                        $details
                    );

                    $results[] = [
                        'index' => $index,
                        'success' => true,
                        'entry_id' => $punchResult['entry']->id ?? null,
                        'type' => $item['type'],
                    ];
                    $anySuccess = true;
                } catch (\Exception $e) {
                    $results[] = [
                        'index' => $index,
                        'success' => false,
                        'reason' => 'processing_error',
                        'message' => $e->getMessage(),
                    ];
                }
            }
        });

        if ($anySuccess) {
            event(new MonitorUpdated($authTenantId));
        }

        usort($results, fn($a, $b) => $a['index'] <=> $b['index']);

        return response()->json([
            'success' => true,
            'processed' => count(array_filter($results, fn($r) => $r['success'])),
            'rejected' => count(array_filter($results, fn($r) => !$r['success'])),
            'results' => array_values($results),
        ]);
    }

    /**
     * Declaración de Contingencia (sin luz / sin internet en sucursal). Mientras esté
     * activa, ClockService::processPunch() protege al 100% el salario del tenant ese día.
     */
    public function declareContingency(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'reason' => ['required', 'string', Rule::in(['no_power', 'no_internet', 'no_power_and_internet'])],
            'declared_at' => 'nullable|date',
            'offline_stamp' => 'nullable|string',
        ]);

        $user = User::find($validated['user_id']);
        if ($user->tenant_id !== auth()->user()->tenant_id) {
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

    /**
     * Evidencia fotográfica de comedor (estados #17/#18b) — recibe la foto en base64
     * (misma captura de cámara que ya usa la PWA para validación facial), la guarda en
     * disco y persiste la referencia. No decide si es obligatoria: eso lo controla el
     * switch require_meal_photo_evidence del lado del frontend.
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
