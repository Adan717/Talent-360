<?php

namespace App\Services;

use App\Models\StoreDailyOpeningStatus;
use App\Models\StoreOpeningAssignment;
use App\Models\StoreOpeningEvent;
use App\Models\StoreLog;
use App\Models\User;
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
        $settings = DB::table('system_settings')->pluck('value', 'key')->toArray();
        $isSimulated = isset($settings['time_mode']) ? json_decode($settings['time_mode'], true) === 'simulated' : false;

        $date = Carbon::now()->format('Y-m-d');
        
        $todayStatus = StoreDailyOpeningStatus::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('date', $date)
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
            $firstResponsible = StoreOpeningAssignment::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('is_active', true)
                ->orderBy('priority_order', 'asc')
                ->first();

            $todayStatus = StoreDailyOpeningStatus::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'date' => $date,
                'scheduled_opening_time' => $openTimeStr,
                'pre_opening_window_start' => $windowStart,
                'report_deadline' => $deadline,
                'current_responsible_employee_id' => $firstResponsible ? $firstResponsible->employee_id : null,
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

        $nowTimeStr = $this->getCurrentTimeStr($simTime);
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
    public function openStoreAndClockIn($userId, $storeId = 1, $simTime = null)
    {
        $user = User::withoutGlobalScopes()->findOrFail($userId);
        $tenantId = $user->tenant_id ?? 1;

        return DB::transaction(function () use ($user, $storeId, $simTime, $tenantId) {
            $status = $this->getTodayOpeningStatus($tenantId, $storeId, $simTime);

            if ($status->status === 'opened') {
                throw new \Exception("La tienda ya se encuentra abierta.");
            }

            // Check if current user is responsible
            if ($status->current_responsible_employee_id !== $user->id) {
                // Check if user has permissions for administrative override
                if ($user->role !== 'admin' && $user->role !== 'supervisor') {
                    throw new \Exception("No eres el encargado responsable de la apertura en este momento.");
                }
            }

            $nowTimeStr = $this->getCurrentTimeStr($simTime);

            // 1. Clock in the employee
            $punchResult = $this->clockService->processPunch($user, 'check_in', $nowTimeStr);

            // 2. Register store opening
            $status->status = 'opened';
            $status->opened_by_employee_id = $user->id;
            $status->opened_at = Carbon::now();
            $status->save();

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
                'date' => Carbon::now()->format('Y-m-d'),
                'type' => 'open',
                'time' => $nowTimeStr,
                'notes' => 'Apertura de tienda por el responsable asignado.',
            ]);

            // 5. Iniciar checklist de apertura automáticamente
            $this->triggerOpeningChecklist($tenantId, $user);

            // Broadcast change via MonitorUpdated event
            event(new \App\Events\MonitorUpdated($tenantId));

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

        $isSuplenteConLlaves = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('employee_id', $requesterId)
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

            $nowTimeStr = $this->getCurrentTimeStr();
            $punchResult = $this->clockService->processPunch($requester, 'check_in', $nowTimeStr);

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
                'admin',
                '🚨 Apertura de Emergencia',
                "{$requester->name} autorizó la apertura de emergencia de la sucursal mediante co-validación de 2 testigos."
            );
            $this->notificationService->sendToRole(
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
     * Trigger opening checklist routing by assigning opening tasks.
     */
    protected function triggerOpeningChecklist($tenantId, User $user)
    {
        try {
            // Find the opening checklist routine
            $routine = DB::table('routines')
                ->where('tenant_id', $tenantId)
                ->where('trigger', 'apertura')
                ->first();

            if ($routine) {
                // Find all tasks related to this routine
                $tasks = DB::table('routine_task')
                    ->where('routine_id', $routine->id)
                    ->pluck('task_id');

                $date = Carbon::now()->format('Y-m-d');

                foreach ($tasks as $taskId) {
                    // Assign to user if not already assigned today
                    $exists = DB::table('task_assignments')
                        ->where('task_id', $taskId)
                        ->where('user_id', $user->id)
                        ->where('date', $date)
                        ->exists();

                    if (!$exists) {
                        DB::table('task_assignments')->insert([
                            'task_id' => $taskId,
                            'user_id' => $user->id,
                            'tenant_id' => $tenantId,
                            'date' => $date,
                            'status' => 'pending',
                            'points_awarded' => 0,
                            'created_at' => Carbon::now(),
                            'updated_at' => Carbon::now(),
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            // Fail-safe
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
            $status = $this->getTodayOpeningStatus($tenantId, $storeId, $simTime);

            if ($status->status === 'opened') {
                throw new \Exception("La tienda ya se encuentra abierta.");
            }

            $nowTimeStr = $this->getCurrentTimeStr($simTime);
            $now = Carbon::createFromFormat('H:i:s', $nowTimeStr);
            $openTime = Carbon::createFromFormat('H:i:s', $status->scheduled_opening_time);

            if ($now->lessThan($openTime)) {
                throw new \Exception("Aún no es la hora de apertura oficial de la tienda.");
            }

            // Update status
            $status->status = 'closed_reported_by_employees';
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

            // Justify check_in for this user automatically if they check in today
            // Store a flag or log the report so they are marked as amnesty-eligible
            return [
                'success' => true,
                'message' => 'Reporte enviado. Se registrará la incidencia para aplicar amnistía de retardo.',
                'status' => $status
            ];
        });
    }

    /**
     * Get current simulated or actual time string formatted as H:i:s.
     */
    public function getCurrentTimeStr($simTime = null)
    {
        $settings = DB::table('system_settings')->pluck('value', 'key')->toArray();
        $isSimulated = isset($settings['time_mode']) ? json_decode($settings['time_mode'], true) === 'simulated' : false;

        if ($isSimulated && $simTime) {
            return $simTime;
        }

        return Carbon::now()->format('H:i:s');
    }
}
