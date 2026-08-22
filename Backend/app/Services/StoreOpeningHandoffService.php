<?php

namespace App\Services;

use App\Helpers\TenantStore;
use App\Models\StoreDailyOpeningStatus;
use App\Models\StoreOpeningAssignment;
use App\Models\StoreOpeningEvent;
use App\Models\User;
use App\Models\Employee;
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
    public function reportOpeningAbsence($userId, $storeId = null, $simTime = null)
    {
        $user = User::withoutGlobalScopes()->findOrFail($userId);
        $tenantId = $user->tenant_id ?? 1;
        // R52: el default era `1` = la sucursal del tenant 1 (los ids de `stores` son globales).
        // Se resuelve aquí porque el método escribe `store_id` (~51).
        $storeId = $storeId ?? TenantStore::defaultIdFor($tenantId);

        return DB::transaction(function () use ($user, $storeId, $simTime, $tenantId) {
            $storeOpeningService = app(StoreOpeningService::class);
            $status = $storeOpeningService->getTodayOpeningStatus($tenantId, $storeId, $simTime);

            if ($status->status === 'opened') {
                throw new \Exception("La tienda ya se encuentra abierta.");
            }

            // Ambos lados son users.id; intval evita falsos negativos int-vs-string.
            if ((int) $status->current_responsible_employee_id !== (int) $user->id) {
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
    public function reportOpeningLate($userId, $storeId, $estimatedArrivalTime, $simTime = null)
    {
        $user = User::withoutGlobalScopes()->findOrFail($userId);
        $tenantId = $user->tenant_id ?? 1;
        // R52: el default era `1` = la sucursal del tenant 1 (los ids de `stores` son globales).
        // Se resuelve aquí porque el método escribe `store_id` (~108). El default se retira del todo:
        // `$estimatedArrivalTime` es obligatorio y va DESPUÉS, así que un opcional aquí sólo era
        // decorativo (PHP lo deprecó en 8.0) y nadie podía omitirlo.
        $storeId = $storeId ?? TenantStore::defaultIdFor($tenantId);

        return DB::transaction(function () use ($user, $storeId, $estimatedArrivalTime, $simTime, $tenantId) {
            $storeOpeningService = app(StoreOpeningService::class);
            $status = $storeOpeningService->getTodayOpeningStatus($tenantId, $storeId, $simTime);

            if ($status->status === 'opened') {
                throw new \Exception("La tienda ya se encuentra abierta.");
            }

            // Ambos lados son users.id; intval evita falsos negativos int-vs-string.
            if ((int) $status->current_responsible_employee_id !== (int) $user->id) {
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
            // R70: se cede la apertura SÓLO si `$mustHandoff` (llega tarde Y el tenant NO permite
            // conservarla). El `|| $willBeLate` anterior hacía la condición ≡ `$willBeLate` (porque
            // `$mustHandoff ⊆ $willBeLate`), así que `allow_late_if_before_opening` era LETRA MUERTA y
            // TODO reporte de retardo cedía la apertura, aunque el ajuste (default TRUE) dijera lo
            // contrario. Ahora el ajuste manda.
            if ($mustHandoff) {
                $handoffResult = $this->handoffToNextResponsible($storeId, $user->id, 'report_late', $simTime, $tenantId);
                $msg = 'Retardo reportado. Debido al horario estimado, se ha cedido la apertura al suplente.';
            } else {
                // No hay cesión: o no llega tarde, o el tenant permite conservar la apertura pese al retardo.
                $msg = $willBeLate
                    ? 'Retardo registrado. Conservas la responsabilidad de la apertura (el horario configurado lo permite).'
                    : 'Retardo registrado. Conservas la responsabilidad por estar dentro del margen.';
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
            // whereDate: 'date' se persiste como datetime; sin esto la re-lectura del
            // status no matcheaba y el handoff retornaba null (nunca cedía la apertura).
            // Fecha en la tz del tenant para que coincida con la que usó getTodayOpeningStatus.
            ->whereDate('date', Carbon::now(app(StoreOpeningService::class)->tenantTimezone($tenantId))->format('Y-m-d'))
            ->first();

        if (!$status) {
            return null;
        }

        $settings = $this->settingsService->getOpeningSettings($tenantId, $storeId);

        // Find current assignment to get order. $currentUserId es un users.id;
        // assignments.employee_id es un employees.id, así que se traduce primero.
        $currentEmployeeId = $this->employeeIdForUserId($currentUserId, $tenantId);
        $currentAssignment = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('employee_id', $currentEmployeeId)
            ->first();

        $currentOrder = $currentAssignment ? $currentAssignment->priority_order : 0;

        // Siguiente responsable AUTORIZADO: `can_open_store` es obligatorio, si no el handoff le
        // pasaba la bolita a alguien a quien el admin le quitó explícitamente el permiso de abrir.
        $nextAssignment = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where('can_open_store', true)
            ->where('priority_order', '>', $currentOrder)
            ->orderBy('priority_order', 'asc')
            ->first();

        // El siguiente responsable: traducir su employee_id a users.id, que es lo
        // que esperan la columna current_responsible_employee_id (FK users),
        // User::find, el evento y la notificación.
        $nextUserId = $nextAssignment ? $this->userIdForEmployeeId($nextAssignment->employee_id) : null;

        // Sólo es una cesión válida si hay un siguiente responsable CON usuario
        // vinculado (un empleado sin user_id no puede iniciar sesión ni abrir). Si el
        // siguiente no tiene usuario, se trata como "sin suplentes" (rama de fallo con
        // alerta crítica) en vez de dejar el responsable en null silenciosamente.
        if ($nextAssignment && $nextUserId !== null) {
            $nextUser = User::withoutGlobalScopes()->find($nextUserId);

            // Update status responsible
            $status->current_responsible_employee_id = $nextUserId;
            $status->status = 'transferred';
            
            // Re-calculate deadline starting from current time
            $nowTimeStr = app(StoreOpeningService::class)->getCurrentTimeStr($simTime, $status->tenant_id);
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
                $this->notificationService->sendToRole($tenantId, 'admin', '⚠️ Cesión de Apertura', 'La apertura de la tienda fue cedida a ' . ($nextUser ? $nextUser->name : 'suplente'));
            }
            if ($settings->notify_supervisor_on_handoff) {
                $this->notificationService->sendToRole($tenantId, 'supervisor', '⚠️ Cesión de Apertura', 'La apertura de la tienda fue cedida a ' . ($nextUser ? $nextUser->name : 'suplente'));
            }

            return [
                'type' => 'transferred',
                'next_responsible_id' => $nextUserId,
                'next_responsible_name' => $nextUser ? $nextUser->name : 'Suplente'
            ];
        } else {
            // Se acabó la cadena. Pero acabarse la cadena NO es lo mismo que perder el día:
            // con un solo portador de llaves se agota de inmediato, y antes eso condenaba el
            // día a la apertura de emergencia (2 testigos) diez minutos ANTES de que la tienda
            // tuviera que abrir. El día sólo se da por perdido cuando la apertura ya no puede
            // contar como a tiempo — mismo umbral que paga el bono.
            // Sólo cuando la cadena se agotó por SILENCIO (el plazo venció sin respuesta) y
            // todavía no es hora de darla por perdida. Si el responsable REPORTÓ que no viene
            // y no hay a quién ceder, el día está perdido ya: no tiene sentido dejárselo a
            // quien acaba de decir que no va a abrir.
            if ($reason === 'no_response' && !app(StoreOpeningService::class)->yaSePuedeDeclararFallida($status, $simTime)) {
                // Sigue habiendo tiempo: el último responsable conserva la apertura.
                $status->status = 'active_window';
                $status->save();

                return [
                    'type' => 'sin_suplentes',
                    'message' => 'No hay más suplentes, pero aún no vence la hora de apertura: la apertura sigue en manos del responsable actual.',
                ];
            }

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

            // Alerta crítica SÓLO a los admin/supervisor DE ESTE TENANT (sendToRole ahora exige
            // tenant_id — antes difundía a los admins de todas las empresas).
            $this->notificationService->sendToRole($tenantId, 'admin', '🚨 ALERTA CRÍTICA: Apertura Fallida', 'Ninguno de los encargados asignados abrió la sucursal a tiempo.');
            $this->notificationService->sendToRole($tenantId, 'supervisor', '🚨 ALERTA CRÍTICA: Apertura Fallida', 'Ninguno de los encargados asignados abrió la sucursal a tiempo.');

            return [
                'type' => 'failed',
                'message' => 'Alerta crítica enviada: Todos los responsables fallaron en responder.'
            ];
        }
    }

    /**
     * Traduce un users.id al employees.id del empleado vinculado dentro del tenant.
     * (Para buscar la asignación de apertura de un usuario, cuyo employee_id es FK
     * a employees.) Devuelve null si no hay empleado vinculado.
     */
    private function employeeIdForUserId($userId, $tenantId): ?int
    {
        // Guard: si $userId es null, `where('user_id', null)` se convierte en
        // whereNull y matchearía cualquier empleado huérfano (user_id null).
        if ($userId === null) {
            return null;
        }
        $employeeId = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->value('id');
        return $employeeId !== null ? (int) $employeeId : null;
    }

    /**
     * Traduce un employees.id (assignments.employee_id) al users.id del empleado.
     * Las columnas runtime de status/evento son FK a users, por eso se guarda users.id.
     */
    private function userIdForEmployeeId($employeeId): ?int
    {
        $userId = Employee::withoutGlobalScopes()
            ->where('id', $employeeId)
            ->value('user_id');
        return $userId !== null ? (int) $userId : null;
    }
}
