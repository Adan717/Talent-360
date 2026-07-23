<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Task;
use App\Models\Routine;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\JobRole;
use App\Events\MonitorUpdated;

class TaskSyncController extends Controller
{
    public function sync(Request $request)
    {
        $request->validate([
            'tasks' => 'nullable|array',
            'tasks.*.id' => 'required|integer',
            'tasks.*.title' => 'required|string|max:255',
            'routines' => 'nullable|array',
            'assignments' => 'nullable|array'
        ]);

        $tenantId = auth()->user()->tenant_id ?? 1;

        // §31: crear/editar el catálogo de tasks/routines es una acción administrativa
        // — la porción de assignments (tomar/pausar/completar tu propia tarea) sigue
        // abierta a cualquier rol autenticado, ese flujo no se toca.
        if ($request->has('tasks') || $request->has('routines')) {
            if (!in_array(auth()->user()->role, ['admin', 'supervisor', 'platform_admin'])) {
                return response()->json(['message' => 'No autorizado para crear o editar tareas/rutinas.'], 403);
            }
        }

        DB::beginTransaction();
        try {
            if ($request->has('tasks')) {
                foreach ($request->input('tasks') as $task) {
                    $taskData = [
                        'title' => $task['title'],
                        'estimated_mins' => $task['estimatedMins'] ?? $task['estimated_mins'] ?? 15,
                        'priority' => $task['priority'] ?? 'normal',
                        'category' => $task['category'] ?? 'operativo',
                        'target_type' => $task['targetType'] ?? $task['target_type'] ?? 'role',
                        'target_id' => $task['targetId'] ?? $task['target_id'] ?? null,
                        'assistant_type' => $task['assistantType'] ?? $task['assistant_type'] ?? 'ninguno',
                        'assistant_prompt' => $task['assistantPrompt'] ?? $task['assistant_prompt'] ?? null,
                        'is_auto_capture' => $task['isAutoCapture'] ?? $task['is_auto_capture'] ?? false,
                        'points' => $task['points'] ?? 10,
                        'validation_mode' => $task['validationMode'] ?? $task['validation_mode'] ?? 'forced',
                        'can_be_done_sitting' => $task['canBeDoneSitting'] ?? $task['can_be_done_sitting'] ?? false,
                        'scheduled_time' => $task['scheduledTime'] ?? $task['scheduled_time'] ?? null,
                        'description' => $task['description'] ?? $task['objective'] ?? null,
                        'validation_criteria' => $task['validationCriteria'] ?? $task['validation_criteria'] ?? null,
                        'frequency' => $task['frequency'] ?? 'Diaria',
                        'evidence_type' => $task['evidenceType'] ?? $task['evidence_type'] ?? 'Supervisión directa',
                        'procedure_steps' => $task['procedureSteps'] ?? $task['procedure_steps'] ?? null,
                        'is_validated' => $task['isValidated'] ?? $task['is_validated'] ?? false,
                        // §38: vincula la tarea a una lección de la Academia (academy_courses.id).
                        'academy_lesson_id' => $task['academyLessonId'] ?? $task['academy_lesson_id'] ?? null,
                        // §35: modo de validación "Comparación (IA)".
                        'ai_comparison_enabled' => $task['aiComparisonEnabled'] ?? $task['ai_comparison_enabled'] ?? false,
                        'ai_reference_images' => $task['aiReferenceImages'] ?? $task['ai_reference_images'] ?? null,
                        'ai_tolerance_description' => $task['aiToleranceDescription'] ?? $task['ai_tolerance_description'] ?? null,
                        'tenant_id' => $tenantId,
                    ];
                    
                    // Buscar tarea que coincida por id Y tenant_id para evitar sobreescritura cruzada
                    $existingTask = Task::withoutGlobalScopes()
                        ->where('id', $task['id'])
                        ->where('tenant_id', $tenantId)
                        ->first();

                    if ($existingTask) {
                        $existingTask->update($taskData);
                    } else {
                        // Si no existe, crearla. Preservamos el ID si no hay conflicto global
                        $idConflicts = Task::withoutGlobalScopes()->where('id', $task['id'])->exists();
                        if (!$idConflicts) {
                            $taskData['id'] = $task['id'];
                            Task::create($taskData);
                        } else {
                            // Si el ID ya existe globalmente, dejamos que PostgreSQL lo autoincremente
                            Task::create($taskData);
                        }
                    }
                }
            }

            if ($request->has('routines')) {
                foreach ($request->input('routines') as $routine) {
                    $routineData = [
                        'title' => $routine['title'],
                        'target_role_id' => $routine['targetRoleId'] ?? $routine['target_role_id'] ?? null,
                        'trigger' => $routine['trigger'],
                        'assign_mode' => $routine['assignMode'] ?? $routine['assign_mode'],
                        'tenant_id' => $tenantId,
                    ];
                    
                    $existingRoutine = Routine::withoutGlobalScopes()
                        ->where('id', $routine['id'])
                        ->where('tenant_id', $tenantId)
                        ->first();

                    if ($existingRoutine) {
                        $existingRoutine->update($routineData);
                        $routineDbId = $existingRoutine->id;
                    } else {
                        $idConflicts = Routine::withoutGlobalScopes()->where('id', $routine['id'])->exists();
                        if (!$idConflicts) {
                            $routineData['id'] = $routine['id'];
                            $newRoutine = Routine::create($routineData);
                            $routineDbId = $newRoutine->id;
                        } else {
                            $newRoutine = Routine::create($routineData);
                            $routineDbId = $newRoutine->id;
                        }
                    }
                    
                    if (isset($routine['taskIds']) && is_array($routine['taskIds'])) {
                        // Limpiar pivot de esta rutina
                        DB::table('routine_task')->where('routine_id', $routineDbId)->delete();
                        foreach ($routine['taskIds'] as $taskId) {
                            $taskExists = Task::withoutGlobalScopes()->where('id', $taskId)->where('tenant_id', $tenantId)->exists();
                            if ($taskExists) {
                                DB::table('routine_task')->insert([
                                    'routine_id' => $routineDbId,
                                    'task_id' => $taskId
                                ]);
                            }
                        }
                    }
                }
            }

            if ($request->has('assignments')) {
                // Fetch tasksConfig for tenant
                $tasksConfigRaw = DB::table('system_settings')
                    ->where('tenant_id', $tenantId)
                    ->where('key', 'tasksConfig')
                    ->value('value');
                $tasksConfig = $tasksConfigRaw ? json_decode($tasksConfigRaw, true) : null;

                foreach ($request->input('assignments') as $assignment) {
                    $assistantData = $assignment['assistantData'] ?? $assignment['assistant_data'] ?? null;
                    if (is_array($assistantData)) {
                        $assistantData = json_encode($assistantData);
                    }

                    $mappedData = [
                        'task_id' => $assignment['taskId'] ?? $assignment['task_id'],
                        'user_id' => $assignment['userId'] ?? $assignment['user_id'] ?? null,
                        'status' => $assignment['status'],
                        'started_at_mins' => $assignment['startedAtMins'] ?? $assignment['started_at_mins'] ?? null,
                        'expected_end_time_mins' => $assignment['expectedEndTimeMins'] ?? $assignment['expected_end_time_mins'] ?? null,
                        'completed_at_mins' => $assignment['completedAtMins'] ?? $assignment['completed_at_mins'] ?? null,
                        'assigned_from_routine_id' => $assignment['assignedFromRoutineId'] ?? $assignment['assigned_from_routine_id'] ?? null,
                        'assistant_data' => $assistantData,
                        'tenant_id' => $tenantId,
                        'accumulated_mins' => $assignment['accumulatedMins'] ?? $assignment['accumulated_mins'] ?? 0,
                        'reserved_at_mins' => $assignment['reservedAtMins'] ?? $assignment['reserved_at_mins'] ?? null,
                        // §40: origen de la asignación para el reporte de cierre del plan de
                        // trabajo diario — el frontend decide el valor, el backend solo lo guarda.
                        'origin' => $assignment['origin'] ?? null,
                    ];

                    // Check if supervisor validation is required
                    $user = User::find($mappedData['user_id']);
                    $reportsTo = false;
                    if ($user && $user->employee && $user->employee->job_role_id) {
                        $role = JobRole::find($user->employee->job_role_id);
                        if ($role && $role->reports_to_role_id) {
                            $reportsTo = true;
                        }
                    }

                    $task = Task::find($mappedData['task_id']);
                    $isBlocker = $task && $task->priority === 'bloqueante';

                    $requiresValidation = false;
                    $tenant = \App\Models\Tenant::find($tenantId);
                    $supervisorUnlocked = $tenant ? $tenant->isFeatureUnlocked('supervisor_validation') : false;

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
                            if ($user && $user->employee && $user->employee->hire_date) {
                                try {
                                    $hireDate = \Carbon\Carbon::parse($user->employee->hire_date);
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

                    $existing = TaskAssignment::withoutGlobalScopes()
                        ->where('id', $assignment['id'])
                        ->where('tenant_id', $tenantId)
                        ->first();

                    if ($existing && $existing->status === 'completed') {
                        $mappedData['status'] = 'completed';
                    } elseif ($mappedData['status'] === 'completed' && $requiresValidation) {
                        $mappedData['status'] = 'awaiting_validation';
                    }

                    // §14.1: date/points_awarded existen en la tabla pero nunca se poblaban.
                    $mappedData['date'] = $assignment['date'] ?? ($existing->date ?? \Carbon\Carbon::today()->toDateString());

                    // Cálculo del Costo Financiero de la Tarea basándose en el salario del empleado:
                    // Costo = (Salario Base Diario / 480 min) * Minutos Acumulados Invertidos
                    $baseSalary = ($user && $user->employee && $user->employee->base_salary > 0) ? (float)$user->employee->base_salary : 300.00;
                    $accumulatedMins = (float)($mappedData['accumulated_mins'] ?? 15);
                    $taskCost = round(($baseSalary / 480) * $accumulatedMins, 2);
                    $mappedData['task_cost'] = $taskCost;

                    if ($mappedData['status'] === 'completed') {
                        // §32: $task puede ser null si el taskId no existe (ej. el
                        // placeholder 9999 de Ley Silla) — sin operador null-safe esto
                        // tronaba con "Attempt to read property 'points' on null" y
                        // hacía rollback de todo el sync.
                        $basePoints = $task?->points ?? 10;
                        $mappedData['points_awarded'] = $basePoints;
                        // 1 Pobre de Puntos = 0.10 Monedas Digitales
                        $coinsEarned = round($basePoints * 0.10, 2);
                        $mappedData['coins_awarded'] = $coinsEarned;

                        // Depositar en el Monedero Digital del usuario si no se ha depositado ya
                        if ($mappedData['user_id'] && (!$existing || $existing->status !== 'completed')) {
                            $wallet = \App\Models\UserWallet::getOrCreateForUser($mappedData['user_id'], $tenantId);
                            $wallet->deposit(
                                $coinsEarned,
                                $basePoints,
                                'earned_task',
                                "Recompensa por completar tarea: " . ($task?->title ?? 'Tarea Operativa'),
                                'TaskAssignment',
                                $assignment['id']
                            );
                        }
                    }

                    if ($existing) {
                        $existing->update($mappedData);
                    } else {
                        $mappedData['id'] = $assignment['id'];
                        TaskAssignment::create($mappedData);
                    }
                }
            }

            DB::commit();

            event(new MonitorUpdated($tenantId));

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::error("Sync tasks exception: " . $e->getMessage(), ['exception' => $e]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }
}
