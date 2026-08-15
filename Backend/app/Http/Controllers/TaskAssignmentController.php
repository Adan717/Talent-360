<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Models\Employee;
use App\Models\JobRole;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Jobs\LogTaskValidationJob;

class TaskAssignmentController extends Controller
{
    /**
     * §31 — Tarea al vuelo (decisión de producto P5-P7, 2026-08-03).
     *
     * Un mando lanza una tarea instantánea ("revisa la gotera del baño") sin pasar por el
     * catálogo. Decisiones textuales del jefe:
     *  - P6: SOLO supervisor/admin, y NUNCA para uno mismo — crear y cobrar la propia tarea
     *    es una máquina de auto-pago; el permiso es el candado anti-fraude.
     *  - P7: paga monedas con las MISMAS reglas que una tarea de rutina: estimated_mins
     *    obligatorio en la puerta (el mismo guardarraíl del catálogo), la evidencia se elige
     *    AL CREARLA (assistant_type), y exige firma del supervisor (validation_mode=forced).
     *    El pago viaja por las puertas ya endurecidas (ancla coins_awarded): aquí no se paga
     *    nada, solo se crea.
     */
    public function alVuelo(Request $request)
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id ?? 1;

        if (!in_array($user->role ?? '', ['admin', 'supervisor', 'platform_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo un supervisor o admin puede lanzar tareas al vuelo.',
            ], 403);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'target_user_id' => 'required|integer',
            'estimated_mins' => 'required|integer|min:1',
            'priority' => 'nullable|string|in:bloqueante,alta,normal,baja',
            'category' => 'nullable|string|max:50',
            'assistant_type' => 'nullable|string|in:ninguno,evidencia_foto,captura_numero',
            'assistant_prompt' => 'nullable|string|max:500',
        ]);

        if ((int) $validated['target_user_id'] === (int) $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes lanzarte una tarea al vuelo a ti mismo: quien crea no se cobra.',
            ], 422);
        }

        $destino = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->find($validated['target_user_id']);

        if (!$destino) {
            return response()->json([
                'success' => false,
                'message' => 'El colaborador destino no pertenece a tu empresa.',
            ], 404);
        }

        return DB::transaction(function () use ($validated, $user, $tenantId, $destino) {
            $now = now();
            $taskId = DB::table('tasks')->insertGetId([
                'tenant_id' => $tenantId,
                'title' => $validated['title'],
                'estimated_mins' => $validated['estimated_mins'],
                'priority' => $validated['priority'] ?? 'normal',
                'category' => $validated['category'] ?? 'operativo',
                'target_type' => 'user',
                'target_id' => $destino->id,
                'assistant_type' => $validated['assistant_type'] ?? 'ninguno',
                'assistant_prompt' => $validated['assistant_prompt'] ?? '',
                'is_auto_capture' => false,
                // P7: igual de formal que una rutina — la firma del supervisor no es opcional.
                'validation_mode' => 'forced',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $date = \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for($tenantId))->toDateString();

            // Mismo patrón determinista/race-safe que los checklists (open_/close_): repetir la
            // creación idéntica el mismo día no duplica la asignación.
            $assignmentId = "fly_{$taskId}_{$destino->id}_{$date}";

            DB::table('task_assignments')->insertOrIgnore([
                'id' => $assignmentId,
                'task_id' => $taskId,
                'user_id' => $destino->id,
                'tenant_id' => $tenantId,
                'date' => $date,
                'status' => 'pending',
                'origin' => 'extra',
                'points_awarded' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Tarea lanzada a {$destino->name}.",
                'task_id' => $taskId,
                'assignment_id' => $assignmentId,
            ], 201);
        });
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        
        $tenantId = $user->tenant_id ?? 1;

        // H25: el día por defecto es el del TENANT, no el del servidor. Con `Carbon::now()` —UTC—
        // y una empresa en UTC-6, desde las 18:00 locales el filtro preguntaba por MAÑANA mientras
        // las asignaciones de la jornada en curso están bajo HOY: el listado salía vacío las
        // últimas seis horas de cada día. El dial llena con esto el checklist de apertura, así que
        // una tienda de horario vespertino lo veía vacío y nunca lo daba por completo.
        // Misma familia que A5/M5 (corte por tenant) y H10 (el dial usaba la fecha del dispositivo).
        $date = $request->input('date', Carbon::now(\App\Helpers\TenantTimezone::for($tenantId))->format('Y-m-d'));
        
        $query = TaskAssignment::where('tenant_id', $tenantId)
            ->where('date', $date);

        // F4 (seguridad): sólo admin/supervisor pueden consultar las assignments de OTRO usuario.
        // Un no privilegiado queda siempre scopeado a las propias (su `?user_id=` se ignora):
        // antes era un IDOR de lectura de la agenda/tiempos/feedback ajenos. Ningún flujo del FE
        // pasa ?user_id= (RelojVisual siempre pide las propias).
        $isPrivileged = in_array($user->role ?? '', ['admin', 'supervisor', 'platform_admin'], true);
        if ($isPrivileged && $request->has('user_id')) {
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

        // F4 (seguridad): ownership — un no privilegiado sólo edita SUS propias assignments.
        // Antes cualquier empleado del tenant podía mover la tarea de un compañero (incluido
        // completarla). El checklist de apertura de RelojVisual actúa sobre las propias.
        $isPrivileged = in_array($user->role ?? '', ['admin', 'supervisor', 'platform_admin'], true);
        if (!$isPrivileged && (int) $assignment->user_id !== (int) $user->id) {
            return response()->json(['error' => 'No puedes modificar tareas de otro colaborador.'], 403);
        }

        // F4 (seguridad): `validated_by` y `validation_feedback` NO son asignables por este
        // endpoint — son campos del SUPERVISOR, que vive en POST /admin/assignments/{id}/validate
        // (jerárquico y auditado). Dejarlos aquí permitía a un empleado forjarse su propia
        // validación o borrar el motivo de rechazo que le dejó su supervisor.
        $validated = $request->validate([
            'status' => 'required|string|in:pending,in_progress,paused,completed,awaiting_validation,omitted,spilled',
            'assistant_data' => 'nullable',
            'accumulated_mins' => 'nullable|integer',
            'started_at_mins' => 'nullable|integer',
            'completed_at_mins' => 'nullable|integer',
            'origin' => 'nullable|string|in:planned,carried_over,extra,routine',
        ]);

        if (isset($validated['assistant_data']) && is_array($validated['assistant_data'])) {
            $validated['assistant_data'] = json_encode($validated['assistant_data']);
        }

        // §33 (punto 1): misma lógica de recálculo que antes solo vivía en
        // TaskSyncController::sync() (líneas ~151-245) — validación de supervisor
        // según validation_mode, costo financiero y puntos/monedas al completar. Se
        // porta aquí tal cual, con el mismo guard de no pagar dos veces.
        // ARBITRAJE F4 — `awaiting_validation` es PEGAJOSO: sólo /validate lo saca. Sin esto, el
        // PUT se saltaba al supervisor (re-roleaba la validación dynamic o degradaba a completed
        // sin firma).
        if ($assignment->status === 'awaiting_validation') {
            $validated['status'] = 'awaiting_validation';
        }

        // ARBITRAJE F4 — el guard §33 "una completada no se recalcula ni se repaga" se conserva,
        // pero ya NO congela el status: DESHACER una completada (desmarcar el checkbox del
        // checklist → pending/in_progress) es un flujo legítimo del Reloj. La garantía de no pagar
        // dos veces pasa a anclarse en `coins_awarded` (abajo), que es la marca real del pago.
        $wasCompleted = $assignment->status === 'completed';

        if ($wasCompleted && $validated['status'] === 'completed') {
            // Sigue completada: no se recalcula nada ni se vuelve a pagar.
        } elseif ($assignment->status === 'awaiting_validation') {
            // Pegajosa: no se recalcula ni se paga hasta que el supervisor valide.
        } else {
            $assignmentUser = $assignment->user_id ? User::find($assignment->user_id) : null;
            $task = $assignment->task_id ? Task::find($assignment->task_id) : null;

            // F4: misma regla ÚNICA que /sync/tasks (TaskValidationPolicy). Antes cada puerta
            // llevaba su propia copia de la lógica de validation_mode/threshold/antigüedad.
            $requiresValidation = \App\Services\TaskValidationPolicy::requiresValidation(
                $tenantId,
                $assignment->user_id,
                $task
            );

            if ($validated['status'] === 'completed' && $requiresValidation) {
                $validated['status'] = 'awaiting_validation';
            }

            $costoPorMinuto = ($assignmentUser && $assignmentUser->employee)
                ? $assignmentUser->employee->costoPorMinuto()
                : 300.00 / 480.0;
            $accumulatedMins = (float) ($validated['accumulated_mins'] ?? $assignment->accumulated_mins ?? 15);
            $validated['task_cost'] = round($costoPorMinuto * $accumulatedMins, 2);

            // El pago sólo ocurre si esta assignment NUNCA se pagó (ancla anti-doble-pago del
            // §33, ahora explícita: `coins_awarded` es la marca del pago, no el status).
            if ($validated['status'] === 'completed' && !((float) ($assignment->coins_awarded ?? 0) > 0)) {
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

    /**
     * §34: marca la asignación como omitida y avisa a quien supervisa al empleado
     * (mismo criterio de jerarquía que TaskValidationController::validateAssignment,
     * con admin/platform_admin como respaldo si el puesto no tiene supervisor directo).
     */
    public function omit(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $tenantId = $user->tenant_id ?? 1;

        $validated = $request->validate([
            'reason' => 'nullable|string',
        ]);

        $assignment = TaskAssignment::where('tenant_id', $tenantId)->findOrFail($id);

        // M1 (auditoría 2026-07-27): ownership — un no privilegiado sólo omite SUS propias
        // asignaciones. Sin esto, cualquier empleado omitía la tarea de cualquier compañero
        // del tenant (sabotaje silencioso). Mismo isPrivileged del módulo.
        $isPrivileged = in_array($user->role ?? '', ['admin', 'supervisor', 'platform_admin'], true);
        if (!$isPrivileged && (int) $assignment->user_id !== (int) $user->id) {
            return response()->json(['error' => 'No puedes omitir tareas de otro colaborador.'], 403);
        }

        $assignment->update([
            'status' => 'omitted',
            'validation_feedback' => $validated['reason'] ?? null,
            // M1: rastro del ACTOR — quién omitió, no sólo de quién era la tarea (mismo uso
            // de validated_by que en reject de resolve-incomplete).
            'validated_by' => $user->id,
        ]);

        $employee = $assignment->user_id ? User::find($assignment->user_id) : null;
        $employeeJobRole = $employee && $employee->employee ? $employee->employee->jobRole : null;

        $notificationService = app(\App\Services\NotificationService::class);
        $taskTitle = $assignment->task->title ?? 'una tarea';
        $reasonText = $validated['reason'] ?? 'sin motivo especificado';
        $title = '⚠️ Tarea omitida';
        // M1: el aviso nombra al ACTOR cuando no es el dueño (antes atribuía la omisión
        // siempre al dueño de la tarea, sin rastro de quién la omitió en realidad).
        if ($employee && (int) $employee->id === (int) $user->id) {
            $body = $employee->name . " omitió la tarea \"{$taskTitle}\". Motivo: {$reasonText}.";
        } else {
            $ownerName = $employee?->name ?? 'la bolsa de trabajo';
            $body = $user->name . " omitió la tarea \"{$taskTitle}\" asignada a {$ownerName}. Motivo: {$reasonText}.";
        }

        $supervisorUserIds = [];
        if ($employeeJobRole) {
            // Se resuelve vía employees.job_role_id (mismo criterio que
            // TaskValidationController::validateAssignment), no users.job_role_id —
            // son columnas distintas y ese es el campo que RRHH mantiene activamente.
            $candidateUsers = User::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with('employee.jobRole')
                ->get();

            foreach ($candidateUsers as $candidate) {
                $candidateJobRole = $candidate->employee ? $candidate->employee->jobRole : null;
                if ($candidateJobRole && $candidateJobRole->isSupervisorOf($employeeJobRole)) {
                    $supervisorUserIds[] = $candidate->id;
                }
            }
        }

        if (!empty($supervisorUserIds)) {
            foreach (array_unique($supervisorUserIds) as $supervisorId) {
                $notificationService->sendToUser($supervisorId, $title, $body);
            }
        } else {
            // Sin supervisor directo resoluble: aviso amplio a admin/platform_admin.
            $notificationService->sendToRole($tenantId, 'admin', $title, $body);
            $notificationService->sendToRole($tenantId, 'platform_admin', $title, $body);
        }

        return response()->json(['success' => true]);
    }

    /**
     * §35: modo de validación "Comparación (IA)". Aplica la misma curva de
     * antigüedad que 'dynamic' (§33) pero con umbral de veterano distinto: <30 días
     * siempre humano, <90 días 50/50, >90 días 90% IA / 10% humano al azar. Si le
     * toca IA y Gemini falla, degrada con gracia a awaiting_validation en vez de
     * fallar la petición.
     */
    public function aiValidate(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $tenantId = $user->tenant_id ?? 1;

        $validated = $request->validate([
            // Tope de 4 MB en base64 (~3 MB de imagen): la cámara del TaskRunner emite JPEG de
            // 900px calidad 0.7 (~150 KB); sin tope, cualquier cliente metía lo que quisiera
            // a la fila y al viaje hacia Gemini.
            'evidence_photo_base64' => 'required|string|max:4200000',
        ]);

        $assignment = TaskAssignment::where('tenant_id', $tenantId)->findOrFail($id);

        // M2 (auditoría 2026-07-27): ownership — un no privilegiado sólo somete evidencia de
        // SUS propias asignaciones. Sin esto, cualquier empleado empujaba la tarea de un
        // compañero a completed (con pago al dueño) o la degradaba a awaiting_validation con
        // fotos basura. Mismo isPrivileged del módulo (update/index/sync).
        $isPrivileged = in_array($user->role ?? '', ['admin', 'supervisor', 'platform_admin'], true);
        if (!$isPrivileged && (int) $assignment->user_id !== (int) $user->id) {
            return response()->json(['error' => 'No puedes someter evidencia por la tarea de otro colaborador.'], 403);
        }

        $task = $assignment->task_id ? Task::find($assignment->task_id) : null;

        if (!$task || $task->validation_mode !== 'ai_comparison' || !$task->ai_comparison_enabled) {
            return response()->json(['error' => 'Esta tarea no tiene habilitada la validación por comparación de IA.'], 422);
        }

        if ($assignment->status === 'completed') {
            return response()->json(['success' => true, 'status' => 'completed']);
        }

        // LA FOTO SE GUARDA PRIMERO (2026-08-13). Este método tiene TRES salidas que mandan la
        // tarea a revisión humana (muestreo por antigüedad, IA no disponible, IA sin match) y
        // ninguna persistía la evidencia: la app le decía al colaborador "tu supervisor lo
        // revisará" y el supervisor abría una tarea SIN foto — la imagen se quedaba en el
        // navegador del empleado. Se guarda apenas llega, antes de cualquier bifurcación.
        $assignment->update(['assistant_data' => $validated['evidence_photo_base64']]);

        $assignmentUser = $assignment->user_id ? User::find($assignment->user_id) : null;
        $days = 0;
        if ($assignmentUser && $assignmentUser->employee && $assignmentUser->employee->hire_date) {
            try {
                $days = abs(now()->diffInDays(Carbon::parse($assignmentUser->employee->hire_date)));
            } catch (\Exception $ex) {
                $days = 0;
            }
        }

        if ($days < 30) {
            $reviewsWithAi = false;
        } elseif ($days < 90) {
            $reviewsWithAi = (mt_rand(1, 100) <= 50);
        } else {
            $reviewsWithAi = (mt_rand(1, 100) <= 90);
        }

        if (!$reviewsWithAi) {
            $assignment->update(['status' => 'awaiting_validation']);
            return response()->json(['success' => true, 'status' => 'awaiting_validation', 'reviewed_by' => 'human_spotcheck']);
        }

        try {
            $referenceImages = is_array($task->ai_reference_images) ? $task->ai_reference_images : [];
            $result = app(\App\Services\GeminiAIService::class)->compareTaskEvidence(
                $validated['evidence_photo_base64'],
                $referenceImages,
                $task->ai_tolerance_description
            );
        } catch (\Exception $e) {
            // Degradación con gracia: si Gemini falla, se manda a revisión humana en
            // vez de fallar la petición completa.
            $assignment->update([
                'status' => 'awaiting_validation',
                'validation_feedback' => 'Validación por IA no disponible en este momento; enviado a revisión humana.',
            ]);
            return response()->json(['success' => true, 'status' => 'awaiting_validation', 'reviewed_by' => 'ai_unavailable']);
        }

        if (!empty($result['match'])) {
            $costoPorMinuto = ($assignmentUser && $assignmentUser->employee)
                ? $assignmentUser->employee->costoPorMinuto()
                : 300.00 / 480.0;
            $accumulatedMins = (float) ($assignment->accumulated_mins ?? 15);
            $basePoints = $task->points ?? 10;
            $coinsEarned = round($basePoints * 0.10, 2);

            // M2 (auditoría 2026-07-27): ancla anti-doble-pago — `coins_awarded` es la marca
            // del pago. Un match sobre una asignación ya pagada (completada y luego
            // desmarcada) depositaba otra vez.
            $yaPagada = (float) ($assignment->coins_awarded ?? 0) > 0;

            $assignment->update([
                'status' => 'completed',
                'ai_validation_result' => $result,
                'task_cost' => round($costoPorMinuto * $accumulatedMins, 2),
                'points_awarded' => $yaPagada ? $assignment->points_awarded : $basePoints,
                'coins_awarded' => $yaPagada ? $assignment->coins_awarded : $coinsEarned,
            ]);

            if ($assignment->user_id && !$yaPagada) {
                $wallet = \App\Models\UserWallet::getOrCreateForUser($assignment->user_id, $tenantId);
                $wallet->deposit(
                    $coinsEarned,
                    $basePoints,
                    'earned_task',
                    "Recompensa por completar tarea (validada por IA): " . $task->title,
                    'TaskAssignment',
                    $assignment->id
                );
            }
        } else {
            $assignment->update([
                'status' => 'awaiting_validation',
                'ai_validation_result' => $result,
                'validation_feedback' => $result['reasoning'] ?? 'La IA no encontró coincidencia con la referencia.',
            ]);
        }

        return response()->json([
            'success' => true,
            'status' => $assignment->status,
            'reviewed_by' => 'ai',
            'ai_result' => $result,
        ]);
    }

    /**
     * §41: valida (o rechaza) una tarea con el PIN de un supervisor, sin que ese
     * supervisor tenga que iniciar sesión en el dispositivo que tiene el colaborador
     * en mano. El PIN prueba identidad; la autorización sobre ESTA asignación se
     * verifica aparte con la misma lógica de TaskValidationController::validateAssignment().
     */
    public function validateWithPin(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $tenantId = $user->tenant_id ?? 1;

        $validated = $request->validate([
            'supervisor_user_id' => 'required|integer',
            'pin' => 'required|string',
            'status' => 'required|in:completed,in_progress',
            'feedback' => 'nullable|string',
        ]);

        $genericError = ['success' => false, 'message' => 'PIN incorrecto o sin permisos para validar esta tarea.'];

        $assignment = TaskAssignment::where('tenant_id', $tenantId)->findOrFail($id);
        $employee = $assignment->user_id ? User::find($assignment->user_id) : null;

        if (!$employee || (int) $validated['supervisor_user_id'] === (int) $employee->id) {
            return response()->json($genericError, 403);
        }

        $supervisorEmployee = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $validated['supervisor_user_id'])
            ->first();

        if (!$supervisorEmployee || !$supervisorEmployee->security_pin || !Hash::check($validated['pin'], $supervisorEmployee->security_pin)) {
            return response()->json($genericError, 422);
        }

        $supervisor = User::withoutGlobalScopes()->find($validated['supervisor_user_id']);

        $isAuthorized = false;
        if ($supervisor && ($supervisor->role === 'admin' || $supervisor->role === 'platform_admin')) {
            $isAuthorized = true;
        } else {
            $supervisorJobRole = $supervisor && $supervisor->employee ? $supervisor->employee->jobRole : null;
            $employeeJobRole = $employee->employee ? $employee->employee->jobRole : null;
            if ($supervisorJobRole && $employeeJobRole) {
                $isAuthorized = $supervisorJobRole->isSupervisorOf($employeeJobRole);
            }
        }

        if (!$isAuthorized) {
            return response()->json($genericError, 403);
        }

        $task = $assignment->task_id ? Task::find($assignment->task_id) : null;

        if ($validated['status'] === 'completed') {
            $basePoints = $task->points ?? 10;
            $coinsEarned = round($basePoints * 0.10, 2);

            // A1 (auditoría 2026-07-27): ancla anti-doble-pago — `coins_awarded` es la marca
            // del pago (la misma que update/validate/resolve-incomplete). Sin ella, el flujo
            // cotidiano (el colaborador completa y cobra vía update; el supervisor valida por
            // PIN después) depositaba una segunda vez, igual que el doble click o el ciclo
            // rechazo→re-validación.
            $yaPagada = (float) ($assignment->coins_awarded ?? 0) > 0;

            $assignment->update([
                'status' => 'completed',
                'validation_feedback' => $validated['feedback'] ?? null,
                'validated_by' => $supervisor->id,
                'score_percentage' => 100,
                'points_awarded' => $yaPagada ? $assignment->points_awarded : $basePoints,
                'coins_awarded' => $yaPagada ? $assignment->coins_awarded : $coinsEarned,
            ]);

            if (!$yaPagada) {
                $wallet = \App\Models\UserWallet::getOrCreateForUser($employee->id, $tenantId);
                $wallet->deposit(
                    $coinsEarned,
                    $basePoints,
                    'earned_task',
                    "Recompensa validada por supervisor vía PIN: " . ($task->title ?? 'Tarea Operativa'),
                    'TaskAssignment',
                    $assignment->id
                );
            }

            LogTaskValidationJob::dispatch($assignment->user_id, $assignment->task_id, $supervisor->id, $tenantId, 'completed');
        } else {
            $assignment->update([
                'status' => 'in_progress',
                'validation_feedback' => $validated['feedback'] ?? null,
                'validated_by' => $supervisor->id,
                'completed_at_mins' => null,
            ]);

            LogTaskValidationJob::dispatch($assignment->user_id, $assignment->task_id, $supervisor->id, $tenantId, 'in_progress', $validated['feedback'] ?? null);
        }

        return response()->json(['success' => true, 'status' => $assignment->status]);
    }

    /**
     * M3 (auditoría 2026-07-27): listado para el panel del gerente de tareas inconclusas.
     * El gate de ROL vive en la ruta (grupo /admin, role:admin,supervisor); aquí sólo se
     * scopea tenant (guard explícito, sin `?? 1`) y se arma la fila con lo que la UI pinta.
     */
    public function flaggedIncomplete(Request $request)
    {
        $user = auth()->user();
        $tenantId = $user->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        $rows = DB::table('task_assignments as a')
            ->leftJoin('tasks as t', function ($join) use ($tenantId) {
                $join->on('t.id', '=', 'a.task_id')->where('t.tenant_id', '=', $tenantId);
            })
            ->leftJoin('employees as e', function ($join) use ($tenantId) {
                $join->on('e.user_id', '=', 'a.user_id')->where('e.tenant_id', '=', $tenantId);
            })
            ->where('a.tenant_id', $tenantId)
            ->where('a.flagged_incomplete', true)
            ->whereNull('a.deleted_at')
            ->orderBy('a.date', 'asc')
            ->get([
                'a.id', 'a.user_id', 'a.date', 'a.status', 'a.accumulated_mins',
                't.title as task_title',
                'e.name as employee_name',
            ]);

        return response()->json($rows);
    }

    /**
     * Sección 2 #2: resuelve una tarea que quedó inconclusa (marcada por el proceso
     * nocturno como flagged_incomplete). Los 3 botones del gerente:
     *   - approve    🟢 Aprobar y proteger bono → completa y paga.
     *   - reschedule 🟡 Reprogramar para hoy    → vuelve a la cola de hoy (origin=carried_over).
     *   - reject     🔴 Rechazar                → se marca omitida (sin pago).
     */
    public function resolveIncomplete(Request $request, $id)
    {
        $user = auth()->user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }

        $tenantId = $user->tenant_id ?? 1;

        $validated = $request->validate([
            'action' => 'required|in:approve,reschedule,reject',
            'feedback' => 'nullable|string',
        ]);

        // C1 (auditoría 2026-07-27): estos son los botones del GERENTE — la ruta vivía en el
        // grupo autenticado general sin gate, así que cualquier empleado se auto-aprobaba (y
        // auto-PAGABA) tareas, o rechazaba las de un compañero. Mismo isPrivileged del módulo.
        $isPrivileged = in_array($user->role ?? '', ['admin', 'supervisor', 'platform_admin'], true);
        if (!$isPrivileged) {
            return response()->json(['error' => 'Sólo un gerente o supervisor puede resolver tareas inconclusas.'], 403);
        }

        $assignment = TaskAssignment::where('tenant_id', $tenantId)->findOrFail($id);

        // C1: anti-auto-validación — nadie resuelve (ni se paga) su propia asignación,
        // mismo criterio que TaskValidationController::validateAssignment.
        if ($assignment->user_id !== null && (int) $assignment->user_id === (int) $user->id) {
            return response()->json(['error' => 'No puedes resolver tus propias tareas inconclusas.'], 403);
        }

        // C1: sólo lo que el proceso nocturno marcó como inconcluso es resoluble por esta
        // puerta — para lo demás están /validate (jerárquico) y el flujo normal.
        if (!$assignment->flagged_incomplete) {
            return response()->json(['error' => 'Esta asignación no está marcada como inconclusa.'], 422);
        }

        $task = $assignment->task_id ? Task::find($assignment->task_id) : null;

        if ($validated['action'] === 'approve') {
            // Aprobar y proteger bono: completa y paga (mismo cálculo que §33), a menos
            // que ya estuviera completada (no se paga dos veces).
            if ($assignment->status !== 'completed') {
                $assignmentUser = $assignment->user_id ? User::find($assignment->user_id) : null;
                $costoPorMinuto = ($assignmentUser && $assignmentUser->employee)
                ? $assignmentUser->employee->costoPorMinuto()
                : 300.00 / 480.0;
                $accumulatedMins = (float) ($assignment->accumulated_mins ?? 15);
                $basePoints = $task?->points ?? 10;
                $coinsEarned = round($basePoints * 0.10, 2);

                // C1: ancla anti-doble-pago — `coins_awarded` es la marca del pago (la misma
                // que usan update/validate). Una asignación que ya cobró se completa sin
                // volver a depositar, conservando lo que se le pagó realmente.
                $yaPagada = (float) ($assignment->coins_awarded ?? 0) > 0;

                $assignment->update([
                    'status' => 'completed',
                    'flagged_incomplete' => false,
                    'validation_feedback' => $validated['feedback'] ?? null,
                    'validated_by' => $user->id,
                    'task_cost' => round($costoPorMinuto * $accumulatedMins, 2),
                    'points_awarded' => $yaPagada ? $assignment->points_awarded : $basePoints,
                    'coins_awarded' => $yaPagada ? $assignment->coins_awarded : $coinsEarned,
                ]);

                if ($assignment->user_id && !$yaPagada) {
                    $wallet = \App\Models\UserWallet::getOrCreateForUser($assignment->user_id, $tenantId);
                    $wallet->deposit(
                        $coinsEarned,
                        $basePoints,
                        'earned_task',
                        "Tarea aprobada por gerencia (inconclusa protegida): " . ($task?->title ?? 'Tarea Operativa'),
                        'TaskAssignment',
                        $assignment->id
                    );
                }
            }
        } elseif ($validated['action'] === 'reschedule') {
            // Reprogramar para hoy: vuelve a la cola operativa de hoy como arrastrada.
            // M5 (auditoría 2026-07-27): "hoy" es el del TENANT, no el del servidor (UTC) —
            // después de las 18:00 CDMX, today() UTC ya es mañana y la tarea reprogramada
            // caía un día adelante del día operativo.
            $assignment->update([
                'status' => 'pending',
                'flagged_incomplete' => false,
                'origin' => 'carried_over',
                'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for($tenantId))->toDateString(),
                'validation_feedback' => $validated['feedback'] ?? null,
                'validated_by' => $user->id,
                'completed_at_mins' => null,
            ]);
        } else { // reject
            $assignment->update([
                'status' => 'omitted',
                'flagged_incomplete' => false,
                'validation_feedback' => $validated['feedback'] ?? null,
                'validated_by' => $user->id,
            ]);
        }

        return response()->json(['success' => true, 'status' => $assignment->status]);
    }
}
