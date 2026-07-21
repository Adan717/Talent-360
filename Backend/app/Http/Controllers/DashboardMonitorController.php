<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\TimeEntry;
use App\Models\InternalMessage;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Events\MonitorUpdated;
use App\Events\NewChatMessage;

class DashboardMonitorController extends Controller
{
    public function getMonitorData(Request $request)
    {
        try {
            $user = auth()->user() ?? auth('sanctum')->user();
            $userTenantId = $user ? $user->tenant_id : 1;

            // Fetch active users belonging to the tenant
            $users = \App\Models\Employee::where('is_active_employee', '!=', false)
                ->with(['jobRole'])
                ->get()
                ->map(function ($emp) {
                    $emp->id = $emp->user_id ?? $emp->id; // Map ID to user_id for compatibility
                    return $emp;
                });

            $today = Carbon::today()->toDateString();

            // Fetch time entries for today to determine shift status
            // whereNull('simulation_session_id'): nunca mezclar fichajes del Simulador
            // Matrix en el monitor de actividad en tiempo real.
            $timeEntries = DB::table('time_entries')
                ->where('tenant_id', $userTenantId)
                ->where('date', $today)
                ->whereNull('simulation_session_id')
                ->get()
                ->groupBy('user_id');

            // Fetch current in-progress and paused task assignments
            // §14.2: sin filtro de tenant/fecha esto podía traer al monitor tareas activas
            // de OTRA empresa (mismo patrón de $completedStats de abajo); tolera date NULL
            // mientras existan filas viejas sin poblar (§14.1).
            $activeAssignments = TaskAssignment::with('task')
                ->whereHas('task', function ($query) use ($userTenantId) {
                    $query->where('tenant_id', $userTenantId);
                })
                ->whereIn('status', ['in_progress', 'paused'])
                ->where(function ($query) use ($today) {
                    $query->whereNull('date')->orWhere('date', $today);
                })
                ->get()
                ->groupBy('user_id');

            // Fetch completed task assignments count and points for today
            $completedStats = DB::table('task_assignments')
                ->join('tasks', 'tasks.id', '=', 'task_assignments.task_id')
                ->where('tasks.tenant_id', $userTenantId)
                ->where('task_assignments.status', 'completed')
                ->whereDate('task_assignments.updated_at', $today)
                ->select(
                    'task_assignments.user_id', 
                    DB::raw('count(*) as total_tasks'),
                    DB::raw('sum(tasks.points) as total_points')
                )
                ->groupBy('task_assignments.user_id')
                ->get()
                ->keyBy('user_id');

            $formattedUsers = $users->map(function ($u) use ($timeEntries, $activeAssignments, $completedStats) {
                $entries = $timeEntries->get($u->id) ?? collect();
                $userAssignments = $activeAssignments->get($u->id) ?? collect();
                $activeTask = $userAssignments->firstWhere('status', 'in_progress');

                // Determine shift status
                $status = 'offline'; // default
                $statusText = 'Fuera de Turno';

                if ($entries->isNotEmpty()) {
                    $latest = $entries->sortByDesc('id')->first();
                    if ($latest->type === 'check_in' || $latest->type === 'meal_end') {
                        $status = 'active';
                        $statusText = 'En Turno';
                    } elseif ($latest->type === 'meal_start') {
                        $status = 'break';
                        $statusText = 'En Descanso';
                    } elseif ($latest->type === 'check_out') {
                        $status = 'offline';
                        $statusText = 'Fuera de Turno';
                    }
                }

                // If they are clocked in but have no active task, they are "idle" (Inactivo / Sin Tarea)
                if (($status === 'active' || $status === 'idle') && !$activeTask) {
                    $status = 'idle';
                    $statusText = 'Inactivo / Sin Tarea';
                }

                // Calculate remaining shift time
                $timeRemaining = 'Jornada terminada';
                if ($status !== 'offline' && $u->shiftEnd) {
                    $now = Carbon::now();
                    $shiftEnd = Carbon::parse($u->shiftEnd);
                    if ($now->lt($shiftEnd)) {
                        $diff = $now->diff($shiftEnd);
                        $timeRemaining = $diff->format('%hh %im');
                    }
                }

                // Calculate daily efficiency
                $completed = $completedStats->get($u->id);
                $completedCount = $completed ? $completed->total_tasks : 0;
                $completedPoints = $completed ? ($completed->total_points ?? 0) : 0;
                $checkInEntry = $entries->firstWhere('type', 'check_in');
                $isLate = $checkInEntry ? $checkInEntry->is_late : false;
                
                $efficiency = 100;
                if ($isLate) {
                    $efficiency -= 15;
                }
                $totalTasksToday = $completedCount + ($activeTask ? 1 : 0);
                if ($totalTasksToday > 0) {
                    $completionRate = $completedCount / $totalTasksToday;
                    $efficiency = round(($efficiency * 0.4) + ($completionRate * 60));
                }

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'role_name' => $u->jobRole ? $u->jobRole->name : 'Colaborador',
                    'status' => $status,
                    'status_text' => $statusText,
                    'active_task' => $activeTask ? [
                        'id' => $activeTask->id,
                        'title' => $activeTask->task->title,
                        'started_at_mins' => $activeTask->started_at_mins,
                        'estimated_mins' => $activeTask->task->estimated_mins,
                        'accumulated_mins' => $activeTask->accumulated_mins ?? 0,
                    ] : null,
                    'active_tasks' => $userAssignments->map(function ($assignment) {
                        return [
                            'id' => $assignment->id,
                            'title' => $assignment->task->title,
                            'started_at_mins' => $assignment->started_at_mins,
                            'estimated_mins' => $assignment->task->estimated_mins,
                            'accumulated_mins' => $assignment->accumulated_mins ?? 0,
                            'status' => $assignment->status,
                        ];
                    })->values()->all(),
                    'completed_tasks_count' => $completedCount,
                    'completed_points' => $completedPoints,
                    'avatar' => $u->avatar ?? 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($u->name),
                    'time_remaining' => $timeRemaining,
                    'shift_start' => $u->shiftStart,
                    'shift_end' => $u->shiftEnd,
                    'efficiency' => $efficiency,
                    'meal_minutes' => $u->mealMinutes ?? 60,
                    'time_entries' => $entries->map(function ($e) {
                        return [
                            'type' => $e->type,
                            'time' => $e->time,
                            'is_late' => (bool)$e->is_late,
                            'late_minutes' => (int)$e->late_minutes,
                            'details' => is_string($e->details) ? json_decode($e->details, true) : $e->details,
                        ];
                    })->values()->all(),
                ];
            })->filter(function ($u) {
                // Solo mostrar usuarios que están en turno (no offline)
                return $u['status'] !== 'offline';
            })->values()->all();

            // Fetch available tasks for quick assignment modal
            $availableTasks = Task::select('id', 'title', 'estimated_mins', 'priority')->get();

            // Fetch live database events
            $timeEntriesFeed = DB::table('time_entries')
                ->join('users', 'users.id', '=', 'time_entries.user_id')
                ->where('time_entries.tenant_id', $userTenantId)
                ->whereNull('time_entries.simulation_session_id')
                ->orderBy('time_entries.created_at', 'desc')
                ->limit(5)
                ->select('time_entries.id', 'users.name', 'time_entries.type', 'time_entries.created_at')
                ->get()
                ->map(function ($entry) {
                    $typeLabels = [
                        'check_in' => 'inició su turno (Check-In)',
                        'meal_start' => 'salió a tomar su descanso de almuerzo',
                        'meal_end' => 'regresó de su descanso de almuerzo',
                        'check_out' => 'finalizó su turno (Check-Out)',
                    ];
                    return [
                        'id' => 'time_' . $entry->id,
                        'user' => $entry->name,
                        'action' => $entry->type,
                        'details' => $typeLabels[$entry->type] ?? 'registró asistencia',
                        'time' => Carbon::parse($entry->created_at)->diffForHumans(),
                        'timestamp' => $entry->created_at,
                    ];
                });

            $taskAssignmentsFeed = DB::table('task_assignments')
                ->join('tasks', 'tasks.id', '=', 'task_assignments.task_id')
                ->join('users', 'users.id', '=', 'task_assignments.user_id')
                ->where('tasks.tenant_id', $userTenantId)
                ->orderBy('task_assignments.created_at', 'desc')
                ->limit(5)
                ->select('task_assignments.id', 'tasks.title', 'users.name', 'task_assignments.status', 'task_assignments.created_at')
                ->get()
                ->map(function ($assignment) {
                    $statusLabels = [
                        'pending' => "tiene pendiente la tarea: '{$assignment->title}'",
                        'in_progress' => "inició la tarea: '{$assignment->title}'",
                        'completed' => "completó la tarea: '{$assignment->title}'",
                        'paused' => "pausó la tarea: '{$assignment->title}'",
                    ];
                    return [
                        'id' => 'task_' . $assignment->id,
                        'user' => $assignment->name,
                        'action' => 'task_' . $assignment->status,
                        'details' => $statusLabels[$assignment->status] ?? "actualizó la tarea '{$assignment->title}'",
                        'time' => Carbon::parse($assignment->created_at)->diffForHumans(),
                        'timestamp' => $assignment->created_at,
                    ];
                });

            $storeLogsFeed = DB::table('store_logs')
                ->join('users', 'users.id', '=', 'store_logs.user_id')
                ->where('users.tenant_id', $userTenantId)
                ->whereNull('store_logs.simulation_session_id')
                ->orderBy('store_logs.created_at', 'desc')
                ->limit(5)
                ->select('store_logs.id', 'users.name', 'store_logs.type', 'store_logs.created_at')
                ->get()
                ->map(function ($log) {
                    $typeLabels = [
                        'open' => 'abrió la tienda/sucursal',
                        'close' => 'cerró la tienda/sucursal',
                    ];
                    return [
                        'id' => 'store_' . $log->id,
                        'user' => $log->name,
                        'action' => 'store_' . $log->type,
                        'details' => $typeLabels[$log->type] ?? "registró evento de sucursal: {$log->type}",
                        'time' => Carbon::parse($log->created_at)->diffForHumans(),
                        'timestamp' => $log->created_at,
                    ];
                });

            $feed = $timeEntriesFeed
                ->concat($taskAssignmentsFeed)
                ->concat($storeLogsFeed)
                ->sortByDesc('timestamp')
                ->values()
                ->take(10)
                ->all();

            // Fetch chat messages (last 50 messages of the tenant)
            $chatMessages = DB::table('internal_messages')
                ->leftJoin('users', 'users.id', '=', 'internal_messages.sender_id')
                ->where('internal_messages.tenant_id', $userTenantId)
                ->whereNull('internal_messages.simulation_session_id')
                ->orderBy('internal_messages.created_at', 'asc')
                ->limit(50)
                ->select('internal_messages.id', 'internal_messages.sender_id', 'users.name as sender_name', 'internal_messages.content', 'internal_messages.type', 'internal_messages.created_at')
                ->get()
                ->map(function ($msg) {
                    return [
                        'id' => $msg->id,
                        'sender_id' => $msg->sender_id,
                        'sender_name' => $msg->sender_name ?? 'Sistema',
                        'content' => $msg->content,
                        'type' => $msg->type,
                        'time' => Carbon::parse($msg->created_at)->diffForHumans(),
                        'timestamp' => $msg->created_at,
                    ];
                })
                ->all();

            // Fetch job roles of the tenant
            $jobRoles = DB::table('job_roles')
                ->where('tenant_id', $userTenantId)
                ->select('id', 'name')
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'users' => $formattedUsers,
                    'available_tasks' => $availableTasks,
                    'feed' => $feed,
                    'chat' => $chatMessages,
                    'job_roles' => $jobRoles,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function assignTask(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'task_id' => 'required|exists:tasks,id',
        ]);

        try {
            $user = auth()->user() ?? auth('sanctum')->user();
            $userTenantId = $user ? $user->tenant_id : 1;

            $assignee = User::findOrFail($request->user_id);
            $task = Task::findOrFail($request->task_id);

            // Reforzar aislamiento de tenants
            if ($assignee->tenant_id !== $userTenantId || $task->tenant_id !== $userTenantId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Operación entre empresas no autorizada.'
                ], 403);
            }

            $assignment = TaskAssignment::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'task_id' => $request->task_id,
                'user_id' => $request->user_id,
                'status' => 'in_progress',
                'started_at_mins' => Carbon::now()->hour * 60 + Carbon::now()->minute,
                'expected_end_time_mins' => Carbon::now()->hour * 60 + Carbon::now()->minute + $task->estimated_mins,
                'date' => Carbon::today()->toDateString(),
            ]);

            event(new MonitorUpdated($userTenantId));

            return response()->json([
                'status' => 'success',
                'message' => 'Tarea asignada correctamente.',
                'data' => $assignment
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function createTask(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'estimated_mins' => 'required|integer|min:1',
            'points' => 'required|integer|min:1',
            'priority' => 'required|string|in:low,medium,high,bloqueante,normal',
            'category' => 'nullable|string',
            'target_type' => 'nullable|string|in:role,user,pool,department',
            'target_id' => 'nullable|integer',
            'assistant_type' => 'nullable|string|in:ninguno,evidencia_foto,captura_numero,texto',
            'assistant_prompt' => 'nullable|string',
            'is_auto_capture' => 'nullable|boolean',
        ]);

        try {
            $user = auth()->user() ?? auth('sanctum')->user();
            $tenantId = $user ? $user->tenant_id : 1;

            $targetType = $request->target_type ?? 'role';
            $targetId = $request->target_id;

            $task = Task::create([
                'title' => $request->title,
                'estimated_mins' => $request->estimated_mins,
                'points' => $request->points,
                'priority' => $request->priority,
                'category' => $request->category ?? 'operativo',
                'target_type' => $targetType,
                'target_id' => $targetId,
                'tenant_id' => $tenantId,
                'assistant_type' => $request->assistant_type ?? 'ninguno',
                'assistant_prompt' => $request->assistant_type !== 'ninguno' ? $request->assistant_prompt : null,
                'is_auto_capture' => $request->is_auto_capture ?? false,
            ]);

            // Si es asignación directa a colaborador (target_type = user), asignamos su user_id
            // directamente. Para role/pool/department la tarea queda sin user_id (disponible
            // para que la reclame o se le asigne quien corresponda según ese target), igual
            // que ya funcionaba para 'role'.
            $assignedUserId = ($targetType === 'user') ? $targetId : null;

            TaskAssignment::create([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'task_id' => $task->id,
                'user_id' => $assignedUserId,
                'status' => 'pending',
                'tenant_id' => $tenantId,
                'date' => Carbon::today()->toDateString(),
            ]);

            event(new MonitorUpdated($tenantId));

            return response()->json([
                'status' => 'success',
                'message' => 'Tarea creada exitosamente en la bolsa de tareas.',
                'data' => $task
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function parseVoiceTask(Request $request)
    {
        $request->validate([
            'text' => 'required|string',
        ]);

        $text = $request->text;
        $user = auth()->user() ?? auth('sanctum')->user();
        $tenantId = $user ? $user->tenant_id : 1;

        // 1. Detect target (User or Role)
        $targetType = 'role';
        $targetId = null;
        $matchedName = null;

        // Fetch all active employees
        $employees = DB::table('employees')
            ->where('tenant_id', $tenantId)
            ->where('is_active_employee', '!=', false)
            ->get();

        foreach ($employees as $emp) {
            $firstName = explode(' ', $emp->name)[0];
            
            // Check full name or first name with word boundaries
            $fullNamePattern = '/\b' . preg_quote($emp->name, '/') . '\b/i';
            $firstNamePattern = '/\b' . preg_quote($firstName, '/') . '\b/i';
            
            if (preg_match($fullNamePattern, $text) || preg_match($firstNamePattern, $text)) {
                $targetType = 'user';
                $targetId = $emp->user_id ?? $emp->id;
                $matchedName = $emp->name;
                break;
            }
        }

        // If no employee matched, check job roles
        if (!$targetId) {
            $roles = DB::table('job_roles')
                ->where('tenant_id', $tenantId)
                ->get();

            foreach ($roles as $role) {
                $rolePattern = '/\b' . preg_quote($role->name, '/') . '\b/i';
                if (preg_match($rolePattern, $text)) {
                    $targetType = 'role';
                    $targetId = $role->id;
                    $matchedName = $role->name;
                    break;
                }
            }
        }

        // 2. Detect Priority
        $priority = 'normal';
        if (stripos($text, 'urgente') !== false || stripos($text, 'bloqueante') !== false) {
            $priority = 'bloqueante';
        } elseif (stripos($text, 'alta') !== false) {
            $priority = 'high';
        } elseif (stripos($text, 'media') !== false) {
            $priority = 'medium';
        }

        // 3. Detect Estimated Time (Mins)
        $estimatedMins = 30; // default
        $timeDetected = false;
        if (preg_match('/(\d+)\s*(minutos|minutos|min|mins)/i', $text, $matches)) {
            $estimatedMins = (int)$matches[1];
            $timeDetected = true;
        } elseif (stripos($text, 'una hora') !== false) {
            $estimatedMins = 60;
            $timeDetected = true;
        } elseif (stripos($text, 'media hora') !== false) {
            $estimatedMins = 30;
            $timeDetected = true;
        } elseif (stripos($text, 'dos horas') !== false) {
            $estimatedMins = 120;
            $timeDetected = true;
        }

        // 4. Detect Category
        $category = 'operativo';
        if (stripos($text, 'limpieza') !== false || stripos($text, 'limpiar') !== false || stripos($text, 'barrer') !== false || stripos($text, 'trapear') !== false) {
            $category = 'limpieza';
        } elseif (stripos($text, 'administrativo') !== false || stripos($text, 'corte') !== false || stripos($text, 'caja') !== false || stripos($text, 'arqueo') !== false) {
            $category = 'administrativo';
        } elseif (stripos($text, 'atención') !== false || stripos($text, 'cliente') !== false || stripos($text, 'vender') !== false || stripos($text, 'ventas') !== false) {
            $category = 'atencion';
        }

        // 5. Detect Assistant Type & Prompt
        $assistantType = 'ninguno';
        $assistantDetected = false;
        $assistantPrompt = '';
        if (preg_match('/(foto|fotografía|fotografica|imagen|cámara)/i', $text)) {
            $assistantType = 'evidencia_foto';
            $assistantDetected = true;
        } elseif (preg_match('/(cantidad|número|numero|cifra|conteo|unidades|contador)/i', $text)) {
            $assistantType = 'captura_numero';
            $assistantDetected = true;
        } elseif (preg_match('/(texto|nota|comentario|escribir|observaciones)/i', $text)) {
            $assistantType = 'texto';
            $assistantDetected = true;
        }

        if ($assistantDetected) {
            if (preg_match('/(pidiendo que|pidiendo|instrucción|instruccion|pregunta|diciendo|que diga)\s+(.+)/i', $text, $promptMatches)) {
                $assistantPrompt = ucfirst(trim($promptMatches[2]));
            }
        }

        // 6. Clean Title extraction
        $title = $text;
        if ($matchedName) {
            $firstName = explode(' ', $matchedName)[0];
            $title = preg_replace('/\b(para|a|de|con|al puesto de)?\s*' . preg_quote($matchedName, '/') . '\b/i', '', $title);
            $title = preg_replace('/\b(para|a|de|con|al puesto de)?\s*' . preg_quote($firstName, '/') . '\b/i', '', $title);
        }

        // Clean prefix commands
        $title = preg_replace('/^\b(crear tarea|crear|nueva tarea|asignar|asigna|asígnale)\b\s*(a|al puesto de|la tarea de|tarea de)?/i', '', $title);

        // Remove time mentions
        $title = preg_replace('/\b(en|durante|de)?\s*(\d+|un|una|dos|tres|cuatro|cinco|seis|siete|ocho|nueve|diez|quince|veinte|treinta|cuarenta|cincuenta|sesenta|noventa)\s*(minutos|minutos|min|mins|horas|hora)\b/i', '', $title);
        $title = preg_replace('/\b(con el asistente de|con asistente de|con evidencia de|con)?\s*(foto|fotografía|fotografica|imagen|cámara|cantidad|número|numero|cifra|conteo|unidades|contador|texto|nota|comentario)\b/i', '', $title);
        $title = preg_replace('/\b(pidiendo que|pidiendo|instrucción|instruccion|pregunta|diciendo|que diga)\s+.+$/i', '', $title);

        // Cleanup multiple spaces and trailing/leading punctuation
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title, " ,.?!;\t\n\r\0\x0B");
        $title = ucfirst($title);

        if (empty($title)) {
            $title = 'Tarea de voz';
        } elseif (strlen($title) > 100) {
            $title = substr($title, 0, 97) . '...';
        }

        $points = max(5, round($estimatedMins / 3));

        return response()->json([
            'status' => 'success',
            'data' => [
                'title' => $title,
                'estimated_mins' => $estimatedMins,
                'points' => $points,
                'priority' => $priority,
                'category' => $category,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'matched_name' => $matchedName,
                'time_detected' => $timeDetected,
                'assistant_type' => $assistantType,
                'assistant_prompt' => $assistantPrompt,
                'assistant_detected' => $assistantDetected,
            ]
        ]);
    }

    public function sendMessage(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
            'type' => 'nullable|string|in:general,permission,food_change,announcement',
        ]);

        try {
            $user = auth()->user() ?? auth('sanctum')->user();
            $tenantId = $user ? $user->tenant_id : 1;

            $messageId = DB::table('internal_messages')->insertGetId([
                'sender_id' => $user ? $user->id : null,
                'type' => $request->type ?? 'general',
                'content' => $request->content,
                'tenant_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $msg = DB::table('internal_messages')
                ->leftJoin('users', 'users.id', '=', 'internal_messages.sender_id')
                ->where('internal_messages.id', $messageId)
                ->select('internal_messages.id', 'internal_messages.sender_id', 'users.name as sender_name', 'internal_messages.content', 'internal_messages.type', 'internal_messages.created_at')
                ->first();

            $chatData = [
                'id' => $msg->id,
                'sender_id' => $msg->sender_id,
                'sender_name' => $msg->sender_name ?? 'Sistema',
                'content' => $msg->content,
                'type' => $msg->type,
                'time' => Carbon::parse($msg->created_at)->diffForHumans(),
                'timestamp' => $msg->created_at,
            ];

            event(new NewChatMessage($tenantId, $chatData));

            return response()->json([
                'status' => 'success',
                'message' => 'Mensaje enviado correctamente.',
                'data' => $chatData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
