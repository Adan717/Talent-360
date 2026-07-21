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

        $user = User::find($request->user_id);

        // Protección anti-Tenant-Spoofing:
        // Verificar que el usuario objetivo pertenece al mismo tenant que el Admin autenticado.
        if ($user->tenant_id !== auth()->user()->tenant_id) {
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
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        foreach ($userIds as $uid) {
            $user = $users->get($uid);
            if (!$user || $user->tenant_id !== $authTenantId) {
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
}
