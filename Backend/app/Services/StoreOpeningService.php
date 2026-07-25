<?php

namespace App\Services;

use App\Models\StoreDailyOpeningStatus;
use App\Models\StoreOpeningAssignment;
use App\Models\StoreOpeningEvent;
use App\Models\StoreLog;
use App\Models\Employee;
use App\Models\User;
use App\Helpers\TenantStore;
use App\Helpers\TenantTimezone;
use App\Models\TimeEntry;
use App\Services\ClockService;
use App\Services\NotificationService;
use App\Services\StoreOpeningSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StoreOpeningService
{
    protected $settingsService;
    protected $clockService;
    protected $notificationService;

    public function __construct(StoreOpeningSettingsService $settingsService, ClockService $clockService, NotificationService $notificationService)
    {
        $this->settingsService = $settingsService;
        $this->clockService = $clockService;
        $this->notificationService = $notificationService;
    }

    /**
     * Get or initialize today's daily opening record.
     */
    public function getTodayOpeningStatus($tenantId, $storeId = 1, $simTime = null, $simDay = null)
    {
        // Merge F3 (fix de frontera de tz, clase StoreOpeningTimezone del Reloj): la FECHA de
        // negocio del status sale de la MISMA zona del tenant que usa processPunch — con la fecha
        // del servidor (UTC), entre 00:00 y 06:00 UTC el status se creaba en "mañana" y el gate de
        // tienda-cerrada (R76) nunca lo veía. El pluck de settings también se acota al tenant.
        $settings = DB::table('system_settings')->where('tenant_id', $tenantId)->pluck('value', 'key')->toArray();
        $isSimulated = isset($settings['time_mode']) ? json_decode($settings['time_mode'], true) === 'simulated' : false;

        $date = Carbon::now($this->tenantTimezone($tenantId))->format('Y-m-d');
        
        $todayStatus = StoreDailyOpeningStatus::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->whereDate('date', $date)
            ->first();

        if (!$todayStatus) {
            // Retrieve opening settings
            $openingSettings = $this->settingsService->getOpeningSettings($tenantId, $storeId);
            
            // Get opening time from store schedule or default to 08:30:00
            $openTimeStr = '08:30:00';
            $companySched = DB::table('system_settings')
                ->where('tenant_id', $tenantId)
                ->where('key', 'storeSchedule')
                ->first();
            if ($companySched) {
                $schedVal = json_decode($companySched->value, true);
                if (isset($schedVal['openTime'])) {
                    $openTimeStr = $schedVal['openTime'] . ':00';
                }
            }

            $openTime = Carbon::createFromFormat('H:i:s', $openTimeStr);
            
            // Calculate windows
            $preMinutes = $openingSettings->pre_opening_window_minutes;
            $reportMinutes = $openingSettings->absence_late_report_window_minutes;
            
            $windowStart = $openTime->copy()->subMinutes($preMinutes)->format('H:i:s');
            $deadline = $openTime->copy()->subMinutes($preMinutes)->addMinutes($reportMinutes)->format('H:i:s');

            // Find current responsible manager (Priority 1)
            // R46 (merge F3): `can_open_store` es el permiso REAL — sin este filtro la columna no
            // gateaba ningun lookup, asi que apagarla desde el panel del admin era decorativo y a
            // esa persona le seguian entregando la apertura del dia. Si nadie esta autorizado, el
            // status queda SIN responsable (no se elige a un no-autorizado por defecto).
            $firstResponsible = StoreOpeningAssignment::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->where('can_open_store', true)
                ->orderBy('priority_order', 'asc')
                ->first();

            // store_opening_assignments.employee_id es employees.id (migración
            // 2026_07_07_192928_fix_store_opening_assignments_foreign_key), pero
            // store_daily_opening_statuses.current_responsible_employee_id sigue siendo
            // users.id (esa migración no tocó esta tabla) — hay que traducir por
            // employees.user_id o la comparación en openStoreAndClockIn/reportAbsence
            // (que sí usa users.id) nunca cuadra con el responsable real.
            $responsibleUserId = $this->responsibleUserId($firstResponsible);

            $todayStatus = StoreDailyOpeningStatus::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'date' => $date,
                'scheduled_opening_time' => $openTimeStr,
                'pre_opening_window_start' => $windowStart,
                'report_deadline' => $deadline,
                'current_responsible_employee_id' => $responsibleUserId,
                'status' => 'pending',
            ]);
        }

        // Auto transition status based on time
        $this->checkAndTransitionStatus($todayStatus, $simTime);

        return $todayStatus;
    }

    /**
     * Transition store daily opening status based on time and rules.
     */
    public function checkAndTransitionStatus(StoreDailyOpeningStatus $status, $simTime = null)
    {
        if (in_array($status->status, ['opened', 'failed', 'closed_reported_by_employees'])) {
            return;
        }

        $nowTimeStr = $this->getCurrentTimeStr($simTime, $status->tenant_id);
        $now = Carbon::createFromFormat('H:i:s', $nowTimeStr);

        $windowStart = Carbon::createFromFormat('H:i:s', $status->pre_opening_window_start);
        $deadline = Carbon::createFromFormat('H:i:s', $status->report_deadline);
        $openingTime = Carbon::createFromFormat('H:i:s', $status->scheduled_opening_time);

        $settings = $this->settingsService->getOpeningSettings($status->tenant_id, $status->store_id);

        if ($now->lessThan($windowStart)) {
            $status->status = 'pending';
        } elseif ($now->greaterThanOrEqualTo($windowStart) && $now->lessThan($deadline)) {
            $status->status = 'active_window';
        } elseif ($now->greaterThanOrEqualTo($deadline)) {
            // Exceeded deadline without action!
            if ($status->status === 'active_window' || $status->status === 'pending' || $status->status === 'transferred') {
                if ($settings->allow_automatic_handoff) {
                    // Try handoff to next responsible
                    $handoffService = app(StoreOpeningHandoffService::class);
                    $handoffService->handoffToNextResponsible($status->store_id, $status->current_responsible_employee_id, 'no_response', $simTime, $status->tenant_id);
                } else {
                    $status->status = 'failed';
                    $status->failed_at = Carbon::now();
                }
            }
        }
        
        $status->save();
    }

    /**
     * Perform the Store Opening & Manager Check-In in a single transaction.
     */
    public function openStoreAndClockIn($userId, $storeId = 1, $simTime = null, $isSimulator = false)
    {
        $user = User::withoutGlobalScopes()->findOrFail($userId);
        $tenantId = $user->tenant_id ?? 1;

        return DB::transaction(function () use ($user, $storeId, $simTime, $tenantId, $isSimulator) {
            $status = $this->getTodayOpeningStatus($tenantId, $storeId, $simTime);

            if ($status->status === 'opened') {
                throw new \Exception("La tienda ya se encuentra abierta.");
            }

            // Check if current user is responsible. A diferencia de otras tablas del
            // codebase, store_opening_assignments.employee_id SÍ es users.id (migración
            // 2026_06_28_030000: ->constrained('users'), y el seed de este mismo archivo
            // inserta users.id reales) — no confundir con el patrón employees.id de
            // otras tablas (pase_lista_ratings, meal_photo_evidences, etc.).
            if (intval($status->current_responsible_employee_id) !== intval($user->id)) {
                // Check if user has permissions for administrative override
                if (!in_array($user->role, ['admin', 'supervisor', 'platform_admin'])) {
                    throw new \Exception("No eres el encargado responsable de la apertura en este momento.");
                }
            }

            $nowTimeStr = $this->getCurrentTimeStr($simTime, $tenantId);

            $simSessionId = null;
            if ($isSimulator) {
                $simSession = DB::table('simulator_sessions')
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'active')
                    ->orderBy('id', 'desc')
                    ->first();
                $simSessionId = $simSession?->id;
            }

            // Merge F3 (reorden, patrón R86): se abre la tienda PRIMERO y el ponche va después en
            // BEST-EFFORT — con el gate de tienda-cerrada de TODOS los planes (R76), fichar antes de
            // abrir bloqueaba el override legítimo de un mando que no es el responsable asignado; y
            // si el ponche falla por una regla propia, la apertura (el objetivo primario) no se cae.
            // 1. Register store opening
            $status->status = 'opened';
            $status->opened_by_employee_id = $user->id;
            $status->opened_at = Carbon::now();
            $status->save();

            // 2. Clock in the employee (con is_simulator si aplica) — SAVEPOINT propio (R51/R84).
            $details = $isSimulator ? ['is_simulator' => true] : [];
            try {
                $punchResult = DB::transaction(function () use ($user, $nowTimeStr, $details) {
                    return $this->clockService->processPunch($user, 'check_in', $nowTimeStr, $details);
                });
            } catch (\Throwable $e) {
                $punchResult = ['success' => false, 'skipped' => true, 'message' => $e->getMessage()];
            }

            // 3. Log event in bitacora
            StoreOpeningEvent::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'employee_id' => $user->id,
                'event_type' => 'open_store',
                'event_status' => 'success',
                'scheduled_opening_time' => $status->scheduled_opening_time,
                'event_time' => Carbon::now(),
                'notes' => 'Apertura de tienda exitosa y registro de entrada laboral completado.',
            ]);

            // 4. Write to StoreLogs
            StoreLog::create([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'simulation_session_id' => $simSessionId,
                'date' => Carbon::now()->format('Y-m-d'),
                'type' => 'open',
                'time' => $nowTimeStr,
                'notes' => 'Apertura de tienda por el responsable asignado.',
            ]);

            // 5. Iniciar checklist de apertura automáticamente
            $this->triggerOpeningChecklist($tenantId, $user);

            // Broadcast: MonitorUpdated + StoreOpened (merge F3 — el segundo faltaba en este
            // camino; el Reloj lo escucha para levantar el gate de tienda-cerrada en vivo).
            event(new \App\Events\MonitorUpdated($tenantId));
            $tenantUserIds = DB::table('users')->where('tenant_id', $tenantId)->pluck('id')->toArray();
            event(new \App\Events\StoreOpened($tenantId, $tenantUserIds));

            return [
                'success' => true,
                'message' => 'Tienda abierta con éxito y entrada registrada.',
                'status' => $status,
                'punch' => $punchResult
            ];
        });
    }

    /**
     * Apertura de Emergencia (estado #9 de la matriz del dialer): un suplente con llaves
     * autoriza la apertura fuera de la ventana normal mediante la co-validación presencial
     * de 2 testigos (PIN). No requiere que el responsable original haya fallado por completo,
     * solo que la apertura oficial esté vencida y haya alguien con llaves dispuesto a entrar.
     */
    public function emergencyOpenWithWitnesses(int $requesterId, int $witness1Id, string $witness1Pin, int $witness2Id, string $witness2Pin, int $storeId = 1): array
    {
        $requester = User::withoutGlobalScopes()->findOrFail($requesterId);
        $tenantId = $requester->tenant_id ?? 1;

        if ($witness1Id === $witness2Id || $witness1Id === $requesterId || $witness2Id === $requesterId) {
            throw new \Exception('Los testigos deben ser dos empleados distintos presentes en sucursal.');
        }

        $witness1 = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($witness1Id);
        $witness2 = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->find($witness2Id);

        if (!$witness1 || !$witness2) {
            throw new \Exception('Los testigos deben ser dos empleados distintos presentes en sucursal.');
        }

        // El PIN de seguridad vive en employees.security_pin (distinto del pin_code de
        // invitación, que se consume/anula tras el onboarding y no sirve como secreto
        // recurrente). Cada empleado lo configura vía PUT /me/security-pin.
        $witness1Employee = $witness1->employee;
        $witness2Employee = $witness2->employee;

        if (!$witness1Employee || !$witness1Employee->security_pin || !Hash::check($witness1Pin, $witness1Employee->security_pin)) {
            throw new \Exception('PIN de testigo incorrecto.');
        }
        if (!$witness2Employee || !$witness2Employee->security_pin || !Hash::check($witness2Pin, $witness2Employee->security_pin)) {
            throw new \Exception('PIN de testigo incorrecto.');
        }

        // store_opening_assignments.employee_id es employees.id, no users.id — hay que
        // resolver el employees.id del requester antes de comparar.
        $requesterEmployeeId = DB::table('employees')->where('user_id', $requesterId)->value('id');

        $isSuplenteConLlaves = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('employee_id', $requesterEmployeeId)
            ->where('is_active', true)
            ->where('has_keys', true)
            ->exists();

        if (!$isSuplenteConLlaves) {
            throw new \Exception('El solicitante no cuenta con llaves de sucursal activas para autorizar la apertura.');
        }

        return DB::transaction(function () use ($requester, $requesterId, $witness1Id, $witness2Id, $storeId, $tenantId) {
            $status = $this->getTodayOpeningStatus($tenantId, $storeId);

            if ($status->status === 'opened') {
                throw new \Exception('La tienda ya se encuentra abierta.');
            }

            $nowTimeStr = $this->getCurrentTimeStr(null, $tenantId);
            try {
                $punchResult = DB::transaction(function () use ($requester, $nowTimeStr) {
                    return $this->clockService->processPunch($requester, 'check_in', $nowTimeStr, ['supervisor_override' => true]);
                });
            } catch (\Throwable $e) {
                $punchResult = ['success' => false, 'skipped' => true, 'message' => $e->getMessage()];
            }

            $status->status = 'opened';
            $status->opened_by_employee_id = $requesterId;
            $status->opened_at = Carbon::now();
            $status->save();

            StoreOpeningEvent::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'employee_id' => $requesterId,
                'event_type' => 'emergency_open',
                'event_status' => 'success',
                'scheduled_opening_time' => $status->scheduled_opening_time,
                'event_time' => Carbon::now(),
                'notes' => 'Apertura de emergencia autorizada mediante co-validación presencial de 2 testigos.',
                'metadata_json' => ['witness_1_id' => $witness1Id, 'witness_2_id' => $witness2Id],
            ]);

            // Alerta prioritaria a RRHH (mismo patrón que las alertas críticas de handoff fallido)
            $this->notificationService->sendToRole(
                $tenantId,
                'admin',
                '🚨 Apertura de Emergencia',
                "{$requester->name} autorizó la apertura de emergencia de la sucursal mediante co-validación de 2 testigos."
            );
            $this->notificationService->sendToRole(
                $tenantId,
                'supervisor',
                '🚨 Apertura de Emergencia',
                "{$requester->name} autorizó la apertura de emergencia de la sucursal mediante co-validación de 2 testigos."
            );

            $userIds = User::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id')->toArray();
            event(new \App\Events\StoreOpened($tenantId, $userIds));
            event(new \App\Events\MonitorUpdated($tenantId));

            return [
                'success' => true,
                'message' => 'Apertura de emergencia autorizada.',
                'status' => $status,
            ];
        });
    }

    /**
     * Checklist de Cierre Seguro (estado #22 de la matriz): 3 ticks (luces, caja fuerte,
     * alarma) antes de poder registrar salida. Espejo del checklist de apertura, reutiliza
     * store_opening_events con event_type='closing_checklist' en vez de crear tabla nueva.
     */
    public function submitClosingChecklist(int $userId, array $checks, int $storeId = 1): array
    {
        $user = User::withoutGlobalScopes()->findOrFail($userId);
        $tenantId = $user->tenant_id ?? 1;
        $date = Carbon::now()->format('Y-m-d');

        $allChecked = ($checks['lights_off'] ?? false)
            && ($checks['safe_secured'] ?? false)
            && ($checks['alarm_activated'] ?? false);

        $eventStatus = $allChecked ? 'success' : 'incomplete';
        $notes = $allChecked
            ? 'Checklist de cierre completado (luces, caja fuerte, alarma activada).'
            : 'Checklist de cierre guardado, aún incompleto.';

        $event = StoreOpeningEvent::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('employee_id', $userId)
            ->where('event_type', 'closing_checklist')
            ->whereDate('event_time', $date)
            ->first();

        if ($event) {
            $event->event_status = $eventStatus;
            $event->metadata_json = $checks;
            $event->event_time = Carbon::now();
            $event->notes = $notes;
            $event->save();
        } else {
            $event = StoreOpeningEvent::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'employee_id' => $userId,
                'event_type' => 'closing_checklist',
                'event_status' => $eventStatus,
                'event_time' => Carbon::now(),
                'notes' => $notes,
                'metadata_json' => $checks,
            ]);
        }

        return [
            'success' => true,
            'message' => $notes,
            'completed' => $allChecked,
        ];
    }

    /**
     * Calificación en Pase de Lista (estado #8): el encargado responsable de la apertura
     * (o admin/supervisor) califica Presentación/Imagen/Energía de cada presente. El
     * check_in en sí sigue el flujo normal de /clock/punch — esto SOLO persiste la
     * calificación. Idempotente por (tenant_id, employee_id, date): recalificar el mismo
     * día actualiza en vez de duplicar.
     */
    public function submitPaseListaRatings(int $raterId, string $date, array $ratings, int $storeId = 1): array
    {
        $rater = User::withoutGlobalScopes()->findOrFail($raterId);
        $tenantId = $rater->tenant_id ?? 1;

        $status = \App\Models\StoreDailyOpeningStatus::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('date', $date)
            ->first();

        $isResponsible = $status && intval($status->current_responsible_employee_id) === intval($raterId);
        $isAdminOrSupervisor = in_array($rater->role, ['admin', 'supervisor', 'platform_admin']);

        if (!$isResponsible && !$isAdminOrSupervisor) {
            throw new \Exception('Solo el encargado responsable de la apertura, un administrador o un supervisor puede calificar el pase de lista.');
        }

        $saved = 0;
        foreach ($ratings as $rating) {
            \App\Models\PaseListaRating::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'employee_id' => $rating['employee_id'],
                    'date' => $date,
                ],
                [
                    'store_id' => $storeId,
                    'rated_by_employee_id' => $raterId,
                    'presentacion' => $rating['presentacion'],
                    'imagen' => $rating['imagen'],
                    'energia' => $rating['energia'],
                ]
            );
            $saved++;
        }

        return [
            'success' => true,
            'message' => 'Calificaciones de pase de lista guardadas.',
            'saved' => $saved,
        ];
    }

    /**
     * Trigger opening checklist routing by assigning opening tasks.
     */
    protected function triggerOpeningChecklist($tenantId, User $user)
    {
        try {
            // TODAS las rutinas de apertura del tenant (no solo la primera): una
            // sucursal puede tener varias (ej. "Apertura Piso" + "Apertura Caja").
            $routines = DB::table('routines')
                ->where('tenant_id', $tenantId)
                ->where('trigger', 'apertura')
                ->get();

            if ($routines->isEmpty()) {
                return;
            }

            $date = Carbon::now($this->tenantTimezone($tenantId))->format('Y-m-d');
            $now = Carbon::now();

            foreach ($routines as $routine) {
                // Join con tasks + filtro por tenant: la rutina ya es del tenant, pero
                // el pivot podría (en teoría) referenciar una tarea de otro tenant;
                // no crear assignments apuntando a tareas ajenas (defensa en profundidad).
                $tasks = DB::table('routine_task')
                    ->join('tasks', 'tasks.id', '=', 'routine_task.task_id')
                    ->where('routine_task.routine_id', $routine->id)
                    ->where('tasks.tenant_id', $tenantId)
                    ->pluck('routine_task.task_id');

                foreach ($tasks as $taskId) {
                    // id determinista sobre (task_id,user_id,date) + insertOrIgnore:
                    // idempotente y race-safe sin un unique global (que rompería
                    // asignaciones legítimas del mismo task/user/día desde otras
                    // fuentes). No incluye routine_id a propósito: una tarea que esté
                    // en varias rutinas de apertura se asigna UNA vez (mismo criterio
                    // de dedup del código original). Dos aperturas concurrentes → una
                    // inserta, la otra es no-op por conflicto de PK.
                    $assignmentId = "open_{$taskId}_{$user->id}_{$date}";

                    DB::table('task_assignments')->insertOrIgnore([
                        'id' => $assignmentId,
                        'task_id' => $taskId,
                        'user_id' => $user->id,
                        'assigned_from_routine_id' => $routine->id,
                        'tenant_id' => $tenantId,
                        'date' => $date,
                        'status' => 'pending',
                        'points_awarded' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Fail-safe: no bloquear la apertura si el checklist falla, pero dejar
            // rastro (antes se tragaba en silencio y ocultaba fallos reales).
            \Illuminate\Support\Facades\Log::error(
                "triggerOpeningChecklist falló para tenant {$tenantId}, user {$user->id}: " . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Employees can report store is closed past the opening schedule to trigger amnesty.
     */
    public function reportStoreStillClosed($userId, $storeId = 1, $simTime = null)
    {
        $user = User::withoutGlobalScopes()->findOrFail($userId);
        $tenantId = $user->tenant_id ?? 1;

        return DB::transaction(function () use ($user, $storeId, $simTime, $tenantId) {
            // R90: el gate del reporte también server-side (antes sólo vivía en el FE): un tenant que
            // apagó la función no debe poder disparar amnistía desde una petición cruda.
            $settings = $this->settingsService->getOpeningSettings($tenantId, $storeId);
            if (!($settings->allow_store_closed_report ?? true)) {
                throw new \Exception("El reporte de tienda cerrada no está habilitado para esta sucursal.");
            }
            // R93 (D2, doble-cerebro cazado en review): el switch que el admin SÍ togglea en el panel es
            // `clockOpConfig.enabledDialerFeatures.allow_store_closed_report`; la columna de arriba
            // (store_opening_settings) quedó SIN UI desde R71 (congelada en true). Se exigen AMBOS.
            if (!ClockService::dialerFeatureEnabled($tenantId, 'allow_store_closed_report')) {
                throw new \Exception("El reporte de tienda cerrada no está habilitado para esta sucursal.");
            }

            $status = $this->getTodayOpeningStatus($tenantId, $storeId, $simTime);

            if ($status->status === 'opened') {
                throw new \Exception("La tienda ya se encuentra abierta.");
            }

            $nowTimeStr = $this->getCurrentTimeStr($simTime, $tenantId);
            $now = Carbon::createFromFormat('H:i:s', $nowTimeStr);
            $openTime = Carbon::createFromFormat('H:i:s', $status->scheduled_opening_time);

            if ($now->lessThan($openTime)) {
                throw new \Exception("Aún no es la hora de apertura oficial de la tienda.");
            }

            // Update status + señal DURABLE de amnistía (R90): que la tienda quedó reportada cerrada hoy.
            // La lee processPunch para amnistiar al EQUIPO cuando la tienda abra tarde; sobrevive la
            // transición del status a `opened`. La amnistía se concede SÓLO si el tenant la tiene
            // activa (`enable_amnesty_if_store_closed`): "reportar sí, amnistiar no" es posible.
            $grantAmnesty = (bool) ($settings->enable_amnesty_if_store_closed ?? true);
            $status->status = 'closed_reported_by_employees';
            $status->late_amnesty_granted = $grantAmnesty;
            $status->save();

            // Log event
            StoreOpeningEvent::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'employee_id' => $user->id,
                'event_type' => 'closed_reported',
                'event_status' => 'success',
                'scheduled_opening_time' => $status->scheduled_opening_time,
                'event_time' => Carbon::now(),
                'notes' => 'El colaborador reportó que la tienda continúa cerrada pasada la hora de apertura oficial.',
            ]);

            return [
                'success' => true,
                'message' => $grantAmnesty
                    ? 'Reporte enviado. Cuando la tienda abra, tu retardo (y el de tus compañeros) por la apertura tardía quedará amnistiado.'
                    : 'Reporte enviado. Se registró la incidencia de tienda cerrada.',
                'status' => $status
            ];
        });
    }

    /**
     * Get current simulated or actual time string formatted as H:i:s.
     */
    public function getCurrentTimeStr($simTime = null, $tenantId = null)
    {
        // Scope por tenant cuando el caller lo pasa (R-fuga: `system_settings.key` es única POR
        // TENANT, no global; sin filtro el pluck leería el time_mode de un tenant arbitrario).
        // Con $tenantId null se conserva el comportamiento previo de esta línea (compat con los
        // callers §1–§42 aún no migrados).
        $settings = DB::table('system_settings')
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->pluck('value', 'key')->toArray();
        $isSimulated = isset($settings['time_mode']) ? json_decode($settings['time_mode'], true) === 'simulated' : false;

        if ($isSimulated && $simTime) {
            return $simTime;
        }

        // Hora real en la TZ DEL TENANT (no UTC): las ventanas / report_deadline del state
        // machine se comparan contra esta hora local (clase StoreOpeningTimezone del Reloj).
        return Carbon::now($tenantId !== null ? $this->tenantTimezone($tenantId) : config('app.timezone'))->format('H:i:s');
    }

    /**
     * Apertura de EMERGENCIA con 2 testigos por PIN DE KIOSKO (R86, línea del Reloj). Los testigos
     * ya vienen RESUELTOS y validados por el controller (blind index + bcrypt + rate-limit R54).
     * Coexiste con emergencyOpenWithWitnesses (§1–§42, testigos por id + security_pin): el
     * controller despacha por protocolo del payload.
     */
    public function emergencyOpenStore($actorUserId, array $witnessUserIds, array $witnessNames, $storeId = null, $simTime = null)
    {
        $actor = User::withoutGlobalScopes()->findOrFail($actorUserId);
        $tenantId = $actor->tenant_id ?? 1;
        $storeId = $storeId ?? 1;

        return DB::transaction(function () use ($actor, $storeId, $simTime, $tenantId, $witnessUserIds, $witnessNames) {
            $status = $this->getTodayOpeningStatus($tenantId, $storeId, $simTime);

            if ($status->status === 'opened') {
                throw new \Exception("La tienda ya se encuentra abierta.");
            }

            $nowTimeStr = $this->getCurrentTimeStr($simTime, $tenantId);

            // Guard de "apertura vencida" (review R86): break-glass para cuando el titular NO llegó
            // a la hora — no una vía para abrir ANTES de horario.
            $now = Carbon::createFromFormat('H:i:s', $nowTimeStr);
            $openTime = Carbon::createFromFormat('H:i:s', $status->scheduled_opening_time);
            if ($now->lessThan($openTime)) {
                throw new \Exception("Aún no es la hora de apertura oficial; la apertura de emergencia sólo aplica si el encargado no llegó a tiempo.");
            }

            // 1. Abrir la tienda PRIMERO (para que el check_in del suplente no lo frene R76).
            $status->status = 'opened';
            $status->opened_by_employee_id = $actor->id;
            $status->opened_at = Carbon::now();
            $status->save();

            // 2. Fichar al actor — BEST-EFFORT en su propio SAVEPOINT (review R86): si el ponche
            //    falla por una regla propia, la tienda se abre igual (lección R51/R84).
            try {
                $punchResult = DB::transaction(function () use ($actor, $nowTimeStr) {
                    return $this->clockService->processPunch($actor, 'check_in', $nowTimeStr, ['supervisor_override' => true]);
                });
            } catch (\Throwable $e) {
                $punchResult = ['success' => false, 'skipped' => true, 'message' => $e->getMessage()];
            }

            $testigosStr = implode(' y ', $witnessNames);

            // 3. Bitácora del evento con los testigos.
            StoreOpeningEvent::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'employee_id' => $actor->id,
                'event_type' => 'emergency_open',
                'event_status' => 'success',
                'scheduled_opening_time' => $status->scheduled_opening_time,
                'event_time' => Carbon::now(),
                'notes' => "Apertura de EMERGENCIA por {$actor->name}, co-validada por 2 testigos presenciales: {$testigosStr}.",
            ]);

            // 4. StoreLog (aviso de apertura).
            StoreLog::create([
                'tenant_id' => $tenantId,
                'user_id' => $actor->id,
                'date' => Carbon::now($this->tenantTimezone($tenantId))->format('Y-m-d'),
                'type' => 'open',
                'time' => $nowTimeStr,
                'notes' => "Apertura de emergencia (2 testigos: {$testigosStr}).",
            ]);

            // 5. Rastro de auditoría PERSISTENTE (append-only).
            DB::table('audit_logs')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $actor->id,
                'date' => Carbon::now($this->tenantTimezone($tenantId))->format('Y-m-d'),
                'type' => 'emergency_store_open',
                'timestamp_str' => $nowTimeStr,
                'reason' => "Apertura de emergencia por {$actor->name}, testigos: {$testigosStr}.",
                'details' => json_encode(['witness_user_ids' => $witnessUserIds]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 6. Checklist de apertura + broadcast (mismo patrón que la apertura normal).
            $this->triggerOpeningChecklist($tenantId, $actor);
            event(new \App\Events\MonitorUpdated($tenantId));
            $tenantUserIds = DB::table('users')->where('tenant_id', $tenantId)->pluck('id')->toArray();
            event(new \App\Events\StoreOpened($tenantId, $tenantUserIds));

            return [
                'success' => true,
                'message' => 'Apertura de emergencia registrada. La tienda quedó abierta.',
                'status' => $status,
                'punch' => $punchResult,
            ];
        });
    }

    /**
     * Zona horaria del tenant (system_settings.timezone), default America/Mexico_City.
     * Delega en el resolver compartido App\Helpers\TenantTimezone.
     */
    public function tenantTimezone($tenantId): string
    {
        return TenantTimezone::for($tenantId);
    }

    /**
     * Traduce el `employee_id` (employees.id) de una asignación al users.id que espera
     * `store_daily_opening_statuses.current_responsible_employee_id` (FK a users pese al nombre).
     * null si la asignación no existe o el expediente no tiene usuario vinculado (huérfano R40).
     */
    protected function responsibleUserId($assignment): ?int
    {
        if (!$assignment) {
            return null;
        }
        $userId = Employee::withoutGlobalScopes()
            ->where('id', $assignment->employee_id)
            ->value('user_id');
        return $userId !== null ? (int) $userId : null;
    }

    /**
     * Asignaciones de apertura tal como las consume el RELOJ (R46/R50): incluye el `user_id` del
     * expediente para que el cliente pueda casar al responsable del día sin adivinar espacios de id.
     */
    public function getAssignmentsForClock(int $tenantId, ?int $storeId = null): array
    {
        $storeId = $storeId ?? 1;

        return StoreOpeningAssignment::withoutGlobalScopes()
            ->where('store_opening_assignments.tenant_id', $tenantId)
            ->where('store_opening_assignments.store_id', $storeId)
            ->leftJoin('employees', 'employees.id', '=', 'store_opening_assignments.employee_id')
            ->orderBy('store_opening_assignments.priority_order', 'asc')
            ->get([
                'store_opening_assignments.employee_id',
                'store_opening_assignments.priority_order',
                'store_opening_assignments.can_open_store',
                'store_opening_assignments.is_active',
                'employees.user_id',
                'employees.name',
            ])
            ->map(fn ($a) => [
                'employee_id' => (int) $a->employee_id,
                // null si el expediente no tiene usuario vinculado (empleado huérfano, R40). El
                // front DEBE distinguir este null de "la llave no viene" (ver lib/apertura.ts).
                'user_id' => $a->user_id !== null ? (int) $a->user_id : null,
                'name' => $a->name,
                'priority_order' => (int) $a->priority_order,
                'can_open_store' => (bool) $a->can_open_store,
                'is_active' => (bool) $a->is_active,
            ])
            ->values()
            ->all();
    }

    /** Resuelve la tz a partir de un arreglo de system_settings ya consultado. */
    private function timezoneFromSettings(array $settings): string
    {
        return TenantTimezone::fromSettings($settings);
    }
}
