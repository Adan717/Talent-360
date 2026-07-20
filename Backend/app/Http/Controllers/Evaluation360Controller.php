<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\PerformanceEvaluation;

class Evaluation360Controller extends Controller
{
    /**
     * GET /api/v1/clock/peers
     * Lista los compañeros disponibles para evaluar (excluye al propio usuario y a quienes ya evaluó este ciclo).
     */
    public function getPeers(Request $request)
    {
        $user     = auth()->user();
        $tenantId = $user->tenant_id ?? 1;

        // IDs de empleados ya evaluados en el ciclo actual (mes en curso)
        $alreadyEvaluated = PerformanceEvaluation::where('evaluator_user_id', $user->id)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->pluck('evaluated_user_id')
            ->toArray();

        $peers = \App\Models\Employee::where('tenant_id', $tenantId)
            ->where('user_id', '!=', $user->id)
            ->whereNotIn('user_id', $alreadyEvaluated)
            ->where('is_active_employee', true)
            ->with('jobRole')
            ->get();

        return response()->json($peers->map(function ($peer) {
            return [
                'id'       => $peer->user_id ?? $peer->id,
                'name'     => $peer->name,
                'job_role' => $peer->jobRole?->name ?? 'Colaborador',
                'initials' => collect(explode(' ', $peer->name))->map(fn($w) => strtoupper($w[0] ?? ''))->take(2)->join(''),
            ];
        })->values());
    }

    /**
     * POST /api/v1/clock/evaluations
     * Registra una evaluación 360° (confidencial — el evaluado nunca ve quién lo evaluó).
     */
    public function store(Request $request)
    {
        $request->validate([
            'evaluated_user_id'  => 'required|integer|exists:users,id',
            'teamwork_score'     => 'required|integer|min:1|max:10',
            'attitude_score'     => 'required|integer|min:1|max:10',
            'performance_score'  => 'required|integer|min:1|max:10',
            'leadership_score'   => 'nullable|integer|min:1|max:10',
            'comments'           => 'nullable|string|max:1000',
        ]);

        $evaluator = auth()->user();
        $tenantId  = $evaluator->tenant_id ?? 1;

        // Verificar que el evaluado esté en el mismo tenant
        $evaluatedUser = User::where('id', $request->evaluated_user_id)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$evaluatedUser) {
            return response()->json(['message' => 'El colaborador no pertenece a tu organización.'], 403);
        }

        // Prevenir doble evaluación en el mismo mes
        $existing = PerformanceEvaluation::where('evaluator_user_id', $evaluator->id)
            ->where('evaluated_user_id', $request->evaluated_user_id)
            ->whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Ya evaluaste a este colaborador en el ciclo actual.'], 409);
        }

        $evaluation = PerformanceEvaluation::create([
            'evaluator_user_id'  => $evaluator->id,
            'evaluated_user_id'  => $request->evaluated_user_id,
            'teamwork_score'     => $request->teamwork_score,
            'performance_score'  => $request->performance_score,
            'attitude_score'     => $request->attitude_score,
            'leadership_score'   => $request->leadership_score ?? $request->performance_score,
            'comments'           => $request->comments,
            'tenant_id'          => $tenantId,
            'cycle_month'        => now()->format('Y-m'),
        ]);

        return response()->json([
            'status'     => 'success',
            'message'    => 'Evaluación registrada confidencialmente ✅',
            'evaluation' => $evaluation,
        ], 201);
    }

    /**
     * GET /api/v1/clock/evaluations/my-results
     * Devuelve los resultados anónimos promedio de las evaluaciones recibidas por el usuario autenticado.
     */
    public function myResults(Request $request)
    {
        $user     = auth()->user();
        $month    = $request->query('month', now()->format('Y-m'));

        $evals = PerformanceEvaluation::where('evaluated_user_id', $user->id)
            ->where('cycle_month', $month)
            ->get();

        if ($evals->isEmpty()) {
            return response()->json([
                'cycle_month'       => $month,
                'evaluations_count' => 0,
                'averages'          => null,
                'message'           => 'Aún no tienes evaluaciones en este ciclo.',
            ]);
        }

        $count = $evals->count();

        return response()->json([
            'cycle_month'       => $month,
            'evaluations_count' => $count,
            'averages'          => [
                'teamwork'    => round($evals->avg('teamwork_score'), 1),
                'attitude'    => round($evals->avg('attitude_score'), 1),
                'performance' => round($evals->avg('performance_score'), 1),
                'leadership'  => round($evals->avg('leadership_score'), 1),
                'overall'     => round($evals->avg(fn($e) => ($e->teamwork_score + $e->attitude_score + $e->performance_score + $e->leadership_score) / 4), 1),
            ],
            // Comentarios sin identificar quién los escribió
            'anonymous_comments' => $evals->pluck('comments')->filter()->values(),
        ]);
    }

    /**
     * GET /api/v1/clock/evaluations/scores
     * Admin: Ranking completo de scores del ciclo actual.
     */
    public function scores(Request $request)
    {
        $user     = auth()->user();
        $tenantId = $user->tenant_id ?? 1;
        $month    = $request->query('month', now()->format('Y-m'));

        $scores = DB::table('performance_evaluations')
            ->join('users', 'users.id', '=', 'performance_evaluations.evaluated_user_id')
            ->leftJoin('employees', 'employees.user_id', '=', 'users.id')
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->where('performance_evaluations.tenant_id', $tenantId)
            ->where('performance_evaluations.cycle_month', $month)
            ->select(
                'users.id as user_id',
                'users.name',
                'job_roles.name as job_role',
                DB::raw('COUNT(*) as evaluations_received'),
                DB::raw('ROUND(AVG(teamwork_score)::numeric, 1) as avg_teamwork'),
                DB::raw('ROUND(AVG(attitude_score)::numeric, 1) as avg_attitude'),
                DB::raw('ROUND(AVG(performance_score)::numeric, 1) as avg_performance'),
                DB::raw('ROUND(AVG(leadership_score)::numeric, 1) as avg_leadership'),
                DB::raw('ROUND(((AVG(teamwork_score) + AVG(attitude_score) + AVG(performance_score) + AVG(leadership_score)) / 4)::numeric, 1) as overall_score')
            )
            ->groupBy('users.id', 'users.name', 'job_roles.name')
            ->orderByDesc('overall_score')
            ->get();

        return response()->json([
            'cycle_month' => $month,
            'scores'      => $scores,
        ]);
    }
}

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
