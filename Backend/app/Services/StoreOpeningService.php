<?php

namespace App\Services;

use App\Models\StoreDailyOpeningStatus;
use App\Models\StoreOpeningAssignment;
use App\Models\StoreOpeningEvent;
use App\Models\StoreLog;
use App\Models\User;
use App\Models\TimeEntry;
use App\Services\ClockService;
use App\Services\StoreOpeningSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StoreOpeningService
{
    protected $settingsService;
    protected $clockService;

    public function __construct(StoreOpeningSettingsService $settingsService, ClockService $clockService)
    {
        $this->settingsService = $settingsService;
        $this->clockService = $clockService;
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
