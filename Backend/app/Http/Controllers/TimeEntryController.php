<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Services\ClockService;
use App\Models\User;
use App\Events\MonitorUpdated;

class TimeEntryController extends Controller
{
    protected $clockService;

    public function __construct(ClockService $clockService)
    {
        $this->clockService = $clockService;
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
}
