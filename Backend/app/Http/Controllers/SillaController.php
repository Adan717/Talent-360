<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Services\ClockService;

class SillaController extends Controller
{
    protected $clockService;

    public function __construct(ClockService $clockService)
    {
        $this->clockService = $clockService;
    }

    public function request(Request $request)
    {
        // H15: la sucursal se resuelve del tenant, no del cliente (con `stores` los ids son globales).
        $storeId = \App\Helpers\TenantStore::defaultIdFor($request->user()->tenant_id ?? 1);

        try {
            $result = $this->clockService->createSillaRequest($request->user(), $storeId);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function approve(Request $request, $id)
    {
        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in(['pin', 'qr', 'remote'])],
            'supervisor_pin' => 'nullable|string',
        ]);

        try {
            $result = $this->clockService->approveSillaRequest(
                (int) $id,
                $request->user(),
                $validated['method'],
                $validated['supervisor_pin'] ?? null
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function reject(Request $request, $id)
    {
        try {
            $result = $this->clockService->rejectSillaRequest((int) $id, $request->user());
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * §25b: panel de supervisor — listar solicitudes por status para poder aprobar/
     * rechazar dentro de la app, sin depender de que el push traiga el request_id.
     */
    public function listRequests(Request $request)
    {
        if (!in_array($request->user()->role, ['admin', 'supervisor', 'platform_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un supervisor o administrador puede ver las solicitudes de Ley Silla.'
            ], 403);
        }

        $tenantId = $request->user()->tenant_id ?? 1;
        $status = $request->query('status', 'pending');
        $date = $request->query('date');
        $storeId = \App\Helpers\TenantStore::defaultIdFor($tenantId); // H15

        $requests = $this->clockService->listSillaRequests($tenantId, $status, $date, $storeId);

        return response()->json(['requests' => $requests]);
    }

    public function status(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $date = $request->query('date', now()->format('Y-m-d'));
        $storeId = \App\Helpers\TenantStore::defaultIdFor($tenantId); // H15

        $status = $this->clockService->getSillaStatus($tenantId, $date, $storeId);

        return response()->json(array_merge(['success' => true], $status));
    }
}
