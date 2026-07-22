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
        $storeId = $request->input('store_id', 1);

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

    public function status(Request $request)
    {
        $tenantId = $request->user()->tenant_id ?? 1;
        $date = $request->query('date', now()->format('Y-m-d'));
        $storeId = $request->query('store_id', 1);

        $status = $this->clockService->getSillaStatus($tenantId, $date, $storeId);

        return response()->json(array_merge(['success' => true], $status));
    }
}
