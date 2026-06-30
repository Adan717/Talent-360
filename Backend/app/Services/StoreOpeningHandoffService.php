<?php

namespace App\Services;

use App\Models\StoreDailyOpeningStatus;
use App\Models\StoreOpeningAssignment;
use App\Models\StoreOpeningEvent;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\StoreOpeningSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StoreOpeningHandoffService
{
    protected $settingsService;
    protected $notificationService;

    public function __construct(StoreOpeningSettingsService $settingsService, NotificationService $notificationService)
    {
        $this->settingsService = $settingsService;
        $this->notificationService = $notificationService;
    }

    /**
     * Report manager absence during opening window.
     */
    public function reportOpeningAbsence($userId, $storeId = 1, $simTime = null)
    {
        $user = User::withoutGlobalScopes()->findOrFail($userId);
        $tenantId = $user->tenant_id ?? 1;

        return DB::transaction(function () use ($user, $storeId, $simTime, $tenantId) {
            $storeOpeningService = app(StoreOpeningService::class);
            $status = $storeOpeningService->getTodayOpeningStatus($tenantId, $storeId, $simTime);

            if ($status->status === 'opened') {
                throw new \Exception("La tienda ya se encuentra abierta.");
            }

            if ($status->current_responsible_employee_id !== $user->id) {
                throw new \Exception("No eres el encargado responsable activo en este momento.");
            }

            // Log absence event
            StoreOpeningEvent::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'employee_id' => $user->id,
                'event_type' => 'report_absence',
                'event_status' => 'success',
                'scheduled_opening_time' => $status->scheduled_opening_time,
                'event_time' => Carbon::now(),
                'notes' => 'El responsable reportó ausencia/incapacidad para abrir la tienda.',
            ]);

            // Handoff to next responsible
            $result = $this->handoffToNextResponsible($storeId, $user->id, 'report_absence', $simTime, $tenantId);

            // Broadcast change
            event(new \App\Events\MonitorUpdated($tenantId));

            return [
                'success' => true,
                'message' => 'Ausencia registrada. Responsabilidad transferida al suplente.',
                'handoff' => $result
            ];
        });
    }

    /**
     * Report manager late arrival during opening window.
     */
    public function reportOpeningLate($userId, $storeId = 1, $estimatedArrivalTime, $simTime = null)
    {
        $user = User::withoutGlobalScopes()->findOrFail($userId);
        $tenantId = $user->tenant_id ?? 1;

        return DB::transaction(function () use ($user, $storeId, $estimatedArrivalTime, $simTime, $tenantId) {
            $storeOpeningService = app(StoreOpeningService::class);
            $status = $storeOpeningService->getTodayOpeningStatus($tenantId, $storeId, $simTime);

            if ($status->status === 'opened') {
                throw new \Exception("La tienda ya se encuentra abierta.");
            }

            if ($status->current_responsible_employee_id !== $user->id) {
                throw new \Exception("No eres el encargado responsable activo en este momento.");
            }

            $settings = $this->settingsService->getOpeningSettings($tenantId, $storeId);

            // Compare estimated arrival time with scheduled opening time
            $eta = Carbon::createFromFormat('H:i', $estimatedArrivalTime);
            $scheduledTime = Carbon::createFromFormat('H:i:s', $status->scheduled_opening_time);

            $willBeLate = $eta->greaterThan($scheduledTime);
            $mustHandoff = $willBeLate && !$settings->allow_late_if_before_opening;

            // Log late report event
            StoreOpeningEvent::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'employee_id' => $user->id,
                'event_type' => 'report_late',
                'event_status' => 'success',
                'scheduled_opening_time' => $status->scheduled_opening_time,
                'event_time' => Carbon::now(),
                'estimated_arrival_time' => $estimatedArrivalTime . ':00',
                'notes' => 'El responsable reportó retardo. Llegada estimada: ' . $estimatedArrivalTime . '.',
            ]);

            $handoffResult = null;
            if ($mustHandoff || $willBeLate) {
                // If it affects official opening, we transfer keys/opening responsibility
                $handoffResult = $this->handoffToNextResponsible($storeId, $user->id, 'report_late', $simTime, $tenantId);
                $msg = 'Retardo reportado. Debido al horario estimado, se ha cedido la apertura al suplente.';
            } else {
                $msg = 'Retardo registrado. Conservas la responsabilidad por estar dentro del margen.';
            }

            // Broadcast change
            event(new \App\Events\MonitorUpdated($tenantId));

            return [
                'success' => true,
                'message' => $msg,
                'handoff' => $handoffResult
            ];
        });
    }

    /**
     * Perform sequential handoff or mark opening failed if all managers fail.
     */
    public function handoffToNextResponsible($storeId, $currentUserId, $reason, $simTime = null, $tenantId = 1)
    {
        $status = StoreDailyOpeningStatus::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('date', Carbon::now()->format('Y-m-d'))
            ->first();

        if (!$status) {
            return null;
        }

        $settings = $this->settingsService->getOpeningSettings($tenantId, $storeId);

        // Find current assignment to get order
        $currentAssignment = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('employee_id', $currentUserId)
            ->first();

        $currentOrder = $currentAssignment ? $currentAssignment->priority_order : 0;

        // Find next active manager
        $nextAssignment = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where('priority_order', '>', $currentOrder)
            ->orderBy('priority_order', 'asc')
            ->first();

        if ($nextAssignment) {
            $nextUserId = $nextAssignment->employee_id;
            $nextUser = User::withoutGlobalScopes()->find($nextUserId);

            // Update status responsible
            $status->current_responsible_employee_id = $nextUserId;
            $status->status = 'transferred';
            
            // Re-calculate deadline starting from current time
            $nowTimeStr = app(StoreOpeningService::class)->getCurrentTimeStr($simTime);
            $now = Carbon::createFromFormat('H:i:s', $nowTimeStr);
            $status->report_deadline = $now->copy()->addMinutes($settings->absence_late_report_window_minutes)->format('H:i:s');
            $status->save();

            // Log handoff event
            StoreOpeningEvent::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'employee_id' => $currentUserId,
                'event_type' => 'handoff_' . $reason,
                'event_status' => 'success',
                'scheduled_opening_time' => $status->scheduled_opening_time,
                'event_time' => Carbon::now(),
                'previous_employee_id' => $currentUserId,
                'next_employee_id' => $nextUserId,
                'notes' => 'Cesión automática/manual de apertura a ' . ($nextUser ? $nextUser->name : 'suplente') . '.',
            ]);

            // Notify subsequent manager
            $this->notificationService->sendToUser(
                $nextUserId,
                '🔑 Responsabilidad de Apertura',
                'Se te ha asignado la apertura de la sucursal de hoy debido a una cesión del encargado anterior.'
            );

            // Notify Admin / Supervisors if enabled
            if ($settings->notify_admin_on_handoff) {
                $this->notificationService->sendToRole('admin', '⚠️ Cesión de Apertura', 'La apertura de la tienda fue cedida a ' . ($nextUser ? $nextUser->name : 'suplente'));
            }
            if ($settings->notify_supervisor_on_handoff) {
                $this->notificationService->sendToRole('supervisor', '⚠️ Cesión de Apertura', 'La apertura de la tienda fue cedida a ' . ($nextUser ? $nextUser->name : 'suplente'));
            }

            return [
                'type' => 'transferred',
                'next_responsible_id' => $nextUserId,
                'next_responsible_name' => $nextUser ? $nextUser->name : 'Suplente'
            ];
        } else {
            // All responsibles failed! Generar alerta crítica.
            $status->status = 'failed';
            $status->failed_at = Carbon::now();
            $status->save();

            StoreOpeningEvent::create([
                'tenant_id' => $tenantId,
                'company_id' => 1,
                'store_id' => $storeId,
                'employee_id' => $currentUserId,
                'event_type' => 'failed_no_responsibles',
                'event_status' => 'failed',
                'scheduled_opening_time' => $status->scheduled_opening_time,
                'event_time' => Carbon::now(),
                'notes' => 'Alerta crítica: Todos los responsables asignados fallaron en abrir la tienda y no hay más suplentes.',
            ]);

            // Broadcast critical alerts to all admins and supervisors
            $this->notificationService->sendToRole('admin', '🚨 ALERTA CRÍTICA: Apertura Fallida', 'Ninguno de los encargados asignados abrió la sucursal a tiempo.');
            $this->notificationService->sendToRole('supervisor', '🚨 ALERTA CRÍTICA: Apertura Fallida', 'Ninguno de los encargados asignados abrió la sucursal a tiempo.');

            return [
                'type' => 'failed',
                'message' => 'Alerta crítica enviada: Todos los responsables fallaron en responder.'
            ];
        }
    }
}
