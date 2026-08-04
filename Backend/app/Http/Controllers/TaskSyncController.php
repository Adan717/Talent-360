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
        // Resync 2026-07-27: el FE del anfitrión también emite ids de assignment NUMÉRICOS
        // (Date.now() en PanelTareasRutinas/createDynamicTask). Se normalizan a string ANTES
        // de validar — exigir string a secas convertía el lote entero en píldora venenosa (422).
        $rawAssignments = $request->input('assignments');
        if (is_array($rawAssignments)) {
            foreach ($rawAssignments as $k => $a) {
                if (is_array($a) && isset($a['id']) && is_numeric($a['id'])) {
                    $rawAssignments[$k]['id'] = (string) $a['id'];
                }
            }
            $request->merge(['assignments' => $rawAssignments]);
        }

        // F4: validacion de input REAL. `task_id` de un assignment NO lleva `exists` a proposito
        // - el mismo payload puede crear una Task nueva y referenciarla (createDynamicTask), y la
        // validacion corre antes de persistir las tasks. El FE manda camelCase y el mapping acepta
        // ambas grafias, por eso cada campo se valida en las dos.
        $request->validate([
            'tasks' => 'nullable|array',
            'tasks.*.id' => 'required|integer',
            'tasks.*.title' => 'required|string|max:255',

            'routines' => 'nullable|array',
            'routines.*.id' => 'required|integer',
            'routines.*.title' => 'required|string|max:255',
            'routines.*.trigger' => 'required|string|max:50',

            'assignments' => 'nullable|array',
            'assignments.*.id' => 'required|string|max:255',
            'assignments.*.task_id' => 'nullable|integer',
            'assignments.*.taskId' => 'nullable|integer',
            'assignments.*.user_id' => 'nullable|integer',
            'assignments.*.userId' => 'nullable|integer',
            // status: solo TIPO aqui. La membresia del enum se valida POR FILA en el loop (skip),
            // NO como rechazo del lote: el sync es full-state y re-emite filas hidratadas del DB;
            // una sola fila legacy con status raro no debe tumbar el sync entero del usuario.
            'assignments.*.status' => 'nullable|string',
            'assignments.*.accumulated_mins' => 'nullable|integer',
            'assignments.*.accumulatedMins' => 'nullable|integer',
            'assignments.*.started_at_mins' => 'nullable|integer',
            'assignments.*.startedAtMins' => 'nullable|integer',
            'assignments.*.expected_end_time_mins' => 'nullable|integer',
            'assignments.*.expectedEndTimeMins' => 'nullable|integer',
            'assignments.*.completed_at_mins' => 'nullable|integer',
            'assignments.*.completedAtMins' => 'nullable|integer',
            'assignments.*.reserved_at_mins' => 'nullable|integer',
            'assignments.*.reservedAtMins' => 'nullable|integer',
        ]);

        $actor = auth()->user();
        $tenantId = $actor->tenant_id ?? 1;
        // Roles con manejo de equipo: pueden escribir assignments de CUALQUIER usuario del tenant.
        // El resto (empleado/employee) solo las propias o las del pool.
        $isPrivileged = in_array($actor->role ?? '', ['admin', 'supervisor', 'platform_admin'], true);

        // §31: crear/editar el catálogo de tasks/routines es una acción administrativa — la
        // porción de assignments (tomar/pausar/completar TU propia tarea) sigue abierta a
        // cualquier rol autenticado, ese flujo no se toca.
        //
        // ARBITRAJE F4 (decisión de PRODUCTO pendiente del jefe): se conserva el 403 DURO de esta
        // línea, que es la postura más restrictiva. La línea del Reloj era más granular — un
        // empleado podía CREAR una tarea dinámica nueva (el botón "tarea al vuelo" del dial) con
        // los campos sensibles forzados a sus defaults (points=10, validation_mode='forced'), y
        // sólo se le impedía EDITAR tareas/rutinas existentes. Con el 403 duro ese botón del dial
        // deja de funcionar para empleados rasos. Si se quiere recuperar, basta sustituir este
        // bloque por el guard granular: rechazar `routines` siempre, y en `tasks` permitir sólo
        // la rama de creación forzando $taskData['points']=10 y ['validation_mode']='forced'.
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
                        // Merge F3: hora programada de la rutina (una sola verdad; el FE la manda
                        // como triggerTime y el motor del reloj la lee de aquí).
                        'trigger_time' => $routine['triggerTime'] ?? $routine['trigger_time'] ?? null,
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

                $validStatuses = ['pending', 'in_progress', 'paused', 'completed', 'awaiting_validation', 'omitted', 'spilled'];

                foreach ($request->input('assignments') as $assignment) {
                    // F4: enum de status por fila (skip, no rechazo del lote): protege contra
                    // basura del cliente sin tumbar el sync full-state por una fila legacy.
                    if (!in_array($assignment['status'] ?? null, $validStatuses, true)) {
                        continue;
                    }

                    $assistantData = $assignment['assistantData'] ?? $assignment['assistant_data'] ?? null;
                    if (is_array($assistantData)) {
                        $assistantData = json_encode($assistantData);
                    }

                    $mappedData = [
                        'task_id' => $assignment['taskId'] ?? $assignment['task_id'] ?? null,
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

                    // Se resuelve la fila existente ANTES de nada para poder blindar el guard de §62.
                    $existing = TaskAssignment::withoutGlobalScopes()
                        ->where('id', $assignment['id'])
                        ->where('tenant_id', $tenantId)
                        ->first();

                    // §62 (🔴 pérdida de datos): NUNCA sobrescribir con null un user_id ya
                    // establecido. Un cliente con el currentUser a medio hidratar (sin
                    // job_role, rol mostrado como "Colaborador") puede reenviar la asignación
                    // con user_id vacío. Si lo guardáramos, GET /task-assignments —que filtra
                    // por user_id— dejaría de encontrarlas y "desaparecerían" del tablero
                    // aunque siguieran en la BD (exactamente el incidente reproducido en prod:
                    // 5 tareas se esfumaron y no volvieron al recargar). Se conserva el dueño
                    // previo; si es una asignación nueva sin dueño válido, no se crea huérfano.
                    //
                    // RESYNC 2026-07-27 — `owner_cleared`: el null SÍ es intencional en los flujos
                    // de BOLSA (releaseTask, rebote de no-concluidas, rutina de horario fijo,
                    // createDynamicTask); aplicar §62 a ciegas los rompía. Esos flujos ahora marcan
                    // la fila con `ownerCleared: true` (intención explícita) y el null se respeta.
                    // Un cliente a medio hidratar nunca manda ese flag → sigue protegido.
                    $ownerCleared = ($assignment['ownerCleared'] ?? $assignment['owner_cleared'] ?? false) === true;
                    if (empty($mappedData['user_id']) && !$ownerCleared) {
                        if ($existing && $existing->user_id) {
                            $mappedData['user_id'] = $existing->user_id;
                        } else {
                            \Illuminate\Support\Facades\Log::warning(
                                "sync/tasks: asignación id={$assignment['id']} llegó sin user_id, sin dueño previo y sin owner_cleared; se omite para no crear un huérfano.",
                                ['tenant_id' => $tenantId, 'caller' => auth()->id()]
                            );
                            continue;
                        }
                    }

                    // F4 (seguridad) - OWNERSHIP. El sync es full-state: las filas vedadas se
                    // OMITEN, no se rechaza la peticion (el cliente re-emite tambien filas ajenas
                    // sin cambios). Sin esto, cualquier empleado podia mover, robar o COMPLETAR
                    // (cobrando monedas) la tarea de un companero.
                    if (!$isPrivileged) {
                        // No tocar assignments cuyo dueno actual sea otro usuario.
                        if ($existing && $existing->user_id !== null && (int) $existing->user_id !== (int) $actor->id) {
                            continue;
                        }
                        // No crear ni reasignar a nombre de otro usuario.
                        if ($mappedData['user_id'] !== null && (int) $mappedData['user_id'] !== (int) $actor->id) {
                            continue;
                        }
                        // Una completacion en el POOL (user_id null, posible via owner_cleared)
                        // evade la validacion jerarquica, que keyea sobre el usuario de la
                        // assignment. Completar exige haber tomado la tarea antes (grab -> user_id).
                        if ($mappedData['user_id'] === null
                            && in_array($mappedData['status'], ['completed', 'awaiting_validation'], true)) {
                            continue;
                        }
                    }

                    // F4: el task_id debe existir EN EL TENANT. Una assignment que apunta a una
                    // tarea inexistente (la "sentado" virtual 9999 de Ley Silla, o basura del
                    // cliente) revienta el FK y, al ser 500, tumbaba TODO el batch full-state.
                    // Se OMITE la fila (misma filosofia skip-no-rechazo).
                    if (!Task::find($mappedData['task_id'])) {
                        continue;
                    }

                    // F4: la regla de validación jerárquica vive en UNA sola clase
                    // (TaskValidationPolicy) compartida con PUT /task-assignments/{id} — antes
                    // estaba duplicada inline en ambas puertas, así que cualquier divergencia
                    // futura dejaba un hueco por donde saltarse al supervisor.
                    $user = User::find($mappedData['user_id']);
                    $task = Task::find($mappedData['task_id']);

                    $requiresValidation = \App\Services\TaskValidationPolicy::requiresValidation(
                        $tenantId,
                        $mappedData['user_id'],
                        $task,
                        $tasksConfig
                    );

                    // ARBITRAJE F4 - `awaiting_validation` PEGAJOSO: solo /admin/assignments/{id}
                    // /validate (jerarquico y auditado) puede sacarla de ahi. Evita el bypass por
                    // reintento en modo dynamic (re-rolear mt_rand) y que un cliente con estado
                    // stale la regrese a in_progress.
                    if ($existing && $existing->status === 'awaiting_validation') {
                        $mappedData['status'] = 'awaiting_validation';
                    } elseif ($existing && $existing->status === 'completed') {
                        $mappedData['status'] = 'completed';
                    } elseif ($mappedData['status'] === 'completed' && $requiresValidation) {
                        $mappedData['status'] = 'awaiting_validation';
                    }

                    // §14.1: date/points_awarded existen en la tabla pero nunca se poblaban.
                    // H25: el día por defecto sale de la zona del TENANT. Con `Carbon::today()`
                    // —UTC— una asignación creada a las 19:00 locales nacía fechada MAÑANA y ya no
                    // aparecía en el listado de su propia jornada.
                    $mappedData['date'] = $assignment['date']
                        ?? ($existing->date ?? \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for($tenantId))->toDateString());

                    // Cálculo del Costo Financiero de la Tarea basándose en el salario del empleado:
                    // Costo = (Salario Base Diario / 480 min) * Minutos Acumulados Invertidos
                    $costoPorMinuto = ($user && $user->employee) ? $user->employee->costoPorMinuto() : 300.00 / 480.0;
                    $accumulatedMins = (float)($mappedData['accumulated_mins'] ?? 15);
                    $taskCost = round($costoPorMinuto * $accumulatedMins, 2);
                    $mappedData['task_cost'] = $taskCost;

                    if ($mappedData['status'] === 'completed') {
                        // §32: $task puede ser null si el taskId no existe (ej. el
                        // placeholder 9999 de Ley Silla) — sin operador null-safe esto
                        // tronaba con "Attempt to read property 'points' on null" y
                        // hacía rollback de todo el sync.
                        $basePoints = $task?->points ?? 10;

                        // A3 (auditoría 2026-07-27): ancla anti-doble-pago — `coins_awarded` es
                        // la marca del pago (la misma que update/validate/validate-with-pin/
                        // resolve-incomplete). El ancla por STATUS pagaba de nuevo tras el ciclo
                        // completar (sync) → desmarcar (PUT, legítimo del checklist del Reloj) →
                        // re-completar (sync). Se conserva ADEMÁS el guard de status: una fila
                        // legacy completada sin coins_awarded (pre-gamificación) tampoco debe
                        // cobrar al re-emitirse en el full-state.
                        $yaPagada = $existing && (float) ($existing->coins_awarded ?? 0) > 0;

                        if ($yaPagada) {
                            // Conservar lo realmente pagado (no re-escribir con puntos nuevos:
                            // el registro debe seguir cuadrando contra el monedero).
                            $mappedData['points_awarded'] = $existing->points_awarded;
                            $mappedData['coins_awarded'] = $existing->coins_awarded;
                        } else {
                            $mappedData['points_awarded'] = $basePoints;
                            // 1 Pobre de Puntos = 0.10 Monedas Digitales
                            $coinsEarned = round($basePoints * 0.10, 2);
                            $mappedData['coins_awarded'] = $coinsEarned;

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
