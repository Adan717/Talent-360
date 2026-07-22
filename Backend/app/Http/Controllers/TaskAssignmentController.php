<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\JobRole;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TaskAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        
        $tenantId = $user->tenant_id ?? 1;
        $date = $request->input('date', Carbon::now()->format('Y-m-d'));
        
        $query = TaskAssignment::where('tenant_id', $tenantId)
            ->where('date', $date);

        if ($request->has('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        } else {
            $query->where('user_id', $user->id);
        }

        $assignments = $query->with('task')->get();

        return response()->json($assignments);
    }

    public function update(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $tenantId = $user->tenant_id ?? 1;

        $assignment = TaskAssignment::where('tenant_id', $tenantId)
            ->findOrFail($id);

        $validated = $request->validate([
            'status' => 'required|string|in:pending,in_progress,paused,completed,awaiting_validation,omitted,spilled',
            'assistant_data' => 'nullable',
            'accumulated_mins' => 'nullable|integer',
            'started_at_mins' => 'nullable|integer',
            'completed_at_mins' => 'nullable|integer',
            'validation_feedback' => 'nullable|string',
            'validated_by' => 'nullable|integer'
        ]);

        if (isset($validated['assistant_data']) && is_array($validated['assistant_data'])) {
            $validated['assistant_data'] = json_encode($validated['assistant_data']);
        }

        // §33 (punto 1): misma lógica de recálculo que antes solo vivía en
        // TaskSyncController::sync() (líneas ~151-245) — validación de supervisor
        // según validation_mode, costo financiero y puntos/monedas al completar. Se
        // porta aquí tal cual, con el mismo guard de no pagar dos veces.
        $wasCompleted = $assignment->status === 'completed';

        if ($wasCompleted) {
            // Una asignación ya completada no vuelve a recalcularse ni a repagarse.
            $validated['status'] = 'completed';
        } else {
            $assignmentUser = $assignment->user_id ? User::find($assignment->user_id) : null;

            $reportsTo = false;
            if ($assignmentUser && $assignmentUser->employee && $assignmentUser->employee->job_role_id) {
                $role = JobRole::find($assignmentUser->employee->job_role_id);
                if ($role && $role->reports_to_role_id) {
                    $reportsTo = true;
                }
            }

            $task = $assignment->task_id ? Task::find($assignment->task_id) : null;
            $isBlocker = $task && $task->priority === 'bloqueante';

            $tasksConfigRaw = DB::table('system_settings')
                ->where('tenant_id', $tenantId)
                ->where('key', 'tasksConfig')
                ->value('value');
            $tasksConfig = $tasksConfigRaw ? json_decode($tasksConfigRaw, true) : null;

            $tenant = \App\Models\Tenant::find($tenantId);
            $supervisorUnlocked = $tenant ? $tenant->isFeatureUnlocked('supervisor_validation') : false;

            $requiresValidation = false;
            if ($supervisorUnlocked && $reportsTo && $task) {
                $mode = $task->validation_mode ?? 'forced';
                if ($mode === 'auto') {
                    $requiresValidation = false;
                } elseif ($mode === 'forced') {
                    if ($tasksConfig && !empty($tasksConfig['requireSupervisorValidation'])) {
                        $threshold = $tasksConfig['validationThreshold'] ?? 'all_tasks';
                        if ($threshold === 'all_tasks') {
                            $requiresValidation = true;
                        } elseif ($threshold === 'blockers_only' && $isBlocker) {
                            $requiresValidation = true;
                        }
                    } else {
                        $requiresValidation = true;
                    }
                } elseif ($mode === 'dynamic') {
                    if ($assignmentUser && $assignmentUser->employee && $assignmentUser->employee->hire_date) {
                        try {
                            $hireDate = Carbon::parse($assignmentUser->employee->hire_date);
                            $days = abs(now()->diffInDays($hireDate));
                        } catch (\Exception $ex) {
                            $days = 0;
                        }
                    } else {
                        $days = 0;
                    }

                    if ($days < 30) {
                        $requiresValidation = true;
                    } elseif ($days < 90) {
                        $requiresValidation = (mt_rand(1, 100) <= 50);
                    } else {
                        $requiresValidation = (mt_rand(1, 100) <= 15);
                    }
                }
            }

            if ($validated['status'] === 'completed' && $requiresValidation) {
                $validated['status'] = 'awaiting_validation';
            }

            $baseSalary = ($assignmentUser && $assignmentUser->employee && $assignmentUser->employee->base_salary > 0)
                ? (float) $assignmentUser->employee->base_salary
                : 300.00;
            $accumulatedMins = (float) ($validated['accumulated_mins'] ?? $assignment->accumulated_mins ?? 15);
            $validated['task_cost'] = round(($baseSalary / 480) * $accumulatedMins, 2);

            if ($validated['status'] === 'completed') {
                $basePoints = $task?->points ?? 10;
                $validated['points_awarded'] = $basePoints;
                $coinsEarned = round($basePoints * 0.10, 2);
                $validated['coins_awarded'] = $coinsEarned;

                if ($assignment->user_id) {
                    $wallet = \App\Models\UserWallet::getOrCreateForUser($assignment->user_id, $tenantId);
                    $wallet->deposit(
                        $coinsEarned,
                        $basePoints,
                        'earned_task',
                        "Recompensa por completar tarea: " . ($task?->title ?? 'Tarea Operativa'),
                        'TaskAssignment',
                        $assignment->id
                    );
                }
            }
        }

        $assignment->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Asignación actualizada con éxito.',
            'assignment' => $assignment->load('task')
        ]);
    }
}
