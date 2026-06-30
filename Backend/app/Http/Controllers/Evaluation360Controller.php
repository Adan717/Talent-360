<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\PerformanceEvaluation;

class Evaluation360Controller extends Controller
{
    /**
     * Get peers to evaluate (other employees in the same tenant)
     */
    public function getPeers(Request $request)
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id ?? 1;

        // Get active users in the same tenant, excluding the current logged-in evaluator
        $peers = \App\Models\Employee::where('tenant_id', $tenantId)
            ->where('user_id', '!=', $user->id)
            ->where('is_active_employee', '!=', false)
            ->with('jobRole')
            ->get();

        return response()->json($peers->map(function ($peer) {
            $roleName = $peer->jobRole->name ?? 'Colaborador';
            return [
                'id' => $peer->user_id ?? $peer->id,
                'name' => "{$peer->name} ({$roleName})",
                'email' => $peer->email,
            ];
        }));
    }

    /**
     * Store a performance evaluation
     */
    public function store(Request $request)
    {
        $request->validate([
            'evaluated_user_id' => 'required|integer|exists:users,id',
            'teamwork_score' => 'required|integer|min:1|max:5',
            'attitude_score' => 'required|integer|min:1|max:5',
            'performance_score' => 'nullable|integer|min:1|max:5',
            'comments' => 'nullable|string'
        ]);

        $evaluator = auth()->user();
        $tenantId = $evaluator->tenant_id ?? 1;

        // Ensure the evaluated user belongs to the same tenant
        $evaluatedUser = User::where('id', $request->evaluated_user_id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$evaluatedUser) {
            return response()->json(['message' => 'El colaborador seleccionado no pertenece a su misma organización.'], 403);
        }

        $evaluation = PerformanceEvaluation::create([
            'evaluator_user_id' => $evaluator->id,
            'evaluated_user_id' => $request->evaluated_user_id,
            'teamwork_score' => $request->teamwork_score,
            'performance_score' => $request->performance_score ?? 5,
            'attitude_score' => $request->attitude_score,
            'comments' => $request->comments,
            'tenant_id' => $tenantId
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Evaluación registrada correctamente.',
            'evaluation' => $evaluation
        ], 201);
    }
}
