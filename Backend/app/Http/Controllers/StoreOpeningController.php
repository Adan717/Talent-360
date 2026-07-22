<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\StoreOpeningSetting;
use App\Models\StoreOpeningAssignment;
use App\Models\StoreDailyOpeningStatus;
use App\Models\CompanyFeature;
use App\Services\FeatureAccessService;
use App\Services\StoreOpeningSettingsService;
use App\Services\StoreOpeningService;
use App\Services\StoreOpeningHandoffService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;

class StoreOpeningController extends Controller
{
    protected $settingsService;
    protected $openingService;
    protected $handoffService;
    protected $notificationService;

    public function __construct(
        StoreOpeningSettingsService $settingsService,
        StoreOpeningService $openingService,
        StoreOpeningHandoffService $handoffService,
        NotificationService $notificationService
    ) {
        $this->settingsService = $settingsService;
        $this->openingService = $openingService;
        $this->handoffService = $handoffService;
        $this->notificationService = $notificationService;
    }

    /**
     * Check if features are active for company.
     */
    public function getCompanyFeatures(Request $request)
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id ?? 1;

        $storeOpeningEnabled = FeatureAccessService::tenantHasFeature($tenantId, 'store_opening');

        return response()->json([
            'tenant_id' => $tenantId,
            'features' => [
                'store_opening' => $storeOpeningEnabled
            ]
        ]);
    }

    /**
     * Get store opening settings.
     */
    public function getSettings(Request $request)
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id ?? 1;

        $settings = $this->settingsService->getOpeningSettings($tenantId);

        return response()->json($settings);
    }

    /**
     * Update store opening settings.
     */
    public function updateSettings(Request $request)
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id ?? 1;

        $validated = $request->validate([
            'is_enabled' => 'boolean',
            'pre_opening_window_minutes' => 'integer|min:1|max:120',
            'absence_late_report_window_minutes' => 'integer|min:1|max:60',
            'early_clock_in_allowed_minutes' => 'integer|min:1|max:60',
            'allow_automatic_handoff' => 'boolean',
            'allow_late_if_before_opening' => 'boolean',
            'allow_store_closed_report' => 'boolean',
            'enable_amnesty_if_store_closed' => 'boolean',
            'require_opening_checklist' => 'boolean',
            'require_closing_checklist' => 'boolean',
            'require_opening_roll_call' => 'boolean',
            'notify_admin_on_handoff' => 'boolean',
            'notify_supervisor_on_handoff' => 'boolean',
        ]);

        $settings = $this->settingsService->getOpeningSettings($tenantId);
        $settings->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Configuración de apertura actualizada.',
            'settings' => $settings
        ]);
    }

    /**
     * Get hierarchy opening assignments.
     */
    public function getAssignments(Request $request)
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id ?? 1;

        $assignments = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('employee:id,name,email,role,user_id')
            ->orderBy('priority_order', 'asc')
            ->get();

        return response()->json($assignments);
    }

    /**
     * Create opening assignment.
     */
    public function createAssignment(Request $request)
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id ?? 1;

        // §30: el endpoint recibe user_id (lo único que el frontend puede conocer con
        // certeza de un colaborador) y resuelve internamente al employees.id real que
        // store_opening_assignments.employee_id exige como FK — simétrico al accessor
        // resolved_user_id agregado en §29 para la lectura.
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'priority_order' => 'integer|min:1',
            'can_open_store' => 'boolean',
            'can_close_store' => 'boolean',
            'has_keys' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $employee = \App\Models\Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $validated['user_id'])
            ->first();

        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Este colaborador no tiene un registro de empleado (employees) asociado. Complétalo en RRHH antes de asignarlo a la apertura.'
            ], 422);
        }

        // Evitar duplicados para el mismo empleado
        $exists = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $employee->id)
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'El colaborador ya cuenta con una asignación de apertura.'
            ], 422);
        }

        unset($validated['user_id']);
        $validated['employee_id'] = $employee->id;
        $validated['tenant_id'] = $tenantId;
        $validated['company_id'] = 1;
        $validated['store_id'] = $request->input('store_id', 1);

        $assignment = StoreOpeningAssignment::create($validated);
        
        // Cargar relación
        $assignment->load('employee:id,name,email,role,user_id');

        return response()->json([
            'success' => true,
            'message' => 'Asignación creada con éxito.',
            'assignment' => $assignment
        ]);
    }

    /**
     * Update opening assignment.
     */
    public function updateAssignment(Request $request, $id)
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id ?? 1;

        $assignment = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $validated = $request->validate([
            'priority_order' => 'integer|min:1',
            'can_open_store' => 'boolean',
            'can_close_store' => 'boolean',
            'has_keys' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $assignment->update($validated);
        $assignment->load('employee:id,name,email,role,user_id');

        return response()->json([
            'success' => true,
            'message' => 'Asignación de apertura actualizada.',
            'assignment' => $assignment
        ]);
    }

    /**
     * Delete opening assignment.
     */
    public function deleteAssignment(Request $request, $id)
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id ?? 1;

        $assignment = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        $assignment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Asignación de apertura eliminada.'
        ]);
    }

    /**
     * Get today's daily opening status.
     */
    public function getTodayStatus(Request $request)
    {
        $user = Auth::user();
        $tenantId = $user->tenant_id ?? 1;
        $storeId = $request->input('store_id', 1);
        $simTime = $request->input('simTime');
        $simDay = $request->input('simDay');

        $status = $this->openingService->getTodayOpeningStatus($tenantId, $storeId, $simTime, $simDay);

        return response()->json([
            'success' => true,
            'status' => $status,
            'is_premium_active' => FeatureAccessService::tenantHasFeature($tenantId, 'store_opening')
        ]);
    }

    /**
     * Atomic process: Open store and Clock In.
     */
    public function openStoreAndClockIn(Request $request)
    {
        $user = Auth::user();
        $storeId = $request->input('store_id', 1);
        $simTime = $request->input('simTime');

        // Permitir suplantación de usuario para el simulador Matrix QA si tiene permisos
        $userId = $user->id;
        if ($request->has('user_id') && (($user->system_role ?? '') === 'platform_admin' || !app()->isProduction() || env('ALLOW_QA_RESET', false))) {
            $userId = intval($request->input('user_id'));
        }

        $isSimulator = $request->boolean('is_simulator') || $request->input('is_simulator') === true;

        try {
            $result = $this->openingService->openStoreAndClockIn($userId, $storeId, $simTime, $isSimulator);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Report absence during opening window.
     */
    public function reportAbsence(Request $request)
    {
        $user = Auth::user();
        $storeId = $request->input('store_id', 1);
        $simTime = $request->input('simTime');

        // Permitir suplantación de usuario para el simulador Matrix QA si tiene permisos
        $userId = $user->id;
        if ($request->has('user_id') && (($user->system_role ?? '') === 'platform_admin' || !app()->isProduction() || env('ALLOW_QA_RESET', false))) {
            $userId = intval($request->input('user_id'));
        }

        try {
            $result = $this->handoffService->reportOpeningAbsence($userId, $storeId, $simTime);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Report late during opening window.
     */
    public function reportLate(Request $request)
    {
        $user = Auth::user();
        $storeId = $request->input('store_id', 1);
        $simTime = $request->input('simTime');
        
        $validated = $request->validate([
            'estimated_arrival_time' => 'required|string|regex:/^\d{2}:\d{2}$/'
        ]);

        // Permitir suplantación de usuario para el simulador Matrix QA si tiene permisos
        $userId = $user->id;
        if ($request->has('user_id') && (($user->system_role ?? '') === 'platform_admin' || !app()->isProduction() || env('ALLOW_QA_RESET', false))) {
            $userId = intval($request->input('user_id'));
        }

        try {
            $result = $this->handoffService->reportOpeningLate(
                $userId,
                $storeId,
                $validated['estimated_arrival_time'],
                $simTime
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Aviso "Enviar Mensaje" (estados #7/#11): el empleado sin llaves esperando en
     * puerta avisa al encargado en camino. Sí existe infraestructura de push
     * server-side en el proyecto (Firebase vía NotificationService), así que se
     * implementa completo, no solo el registro/WebSocket de respaldo.
     */
    public function doorNotice(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'responsible_employee_id' => 'nullable|integer|exists:users,id',
            'message' => 'required|string|max:500',
            'store_id' => 'nullable|integer',
        ]);

        $fromUser = auth()->user();
        $tenantId = $fromUser->tenant_id;
        $storeId = $validated['store_id'] ?? 1;

        $toEmployeeId = $validated['responsible_employee_id'] ?? null;
        if (!$toEmployeeId) {
            $status = \App\Models\StoreDailyOpeningStatus::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('store_id', $storeId)
                ->where('date', $validated['date'])
                ->first();
            $toEmployeeId = $status?->current_responsible_employee_id;
        }

        if (!$toEmployeeId) {
            return response()->json([
                'success' => false,
                'message' => 'No se pudo determinar a quién avisar (no hay encargado responsable asignado hoy).'
            ], 422);
        }

        \App\Models\DoorNotice::create([
            'tenant_id' => $tenantId,
            'from_employee_id' => $fromUser->id,
            'to_employee_id' => $toEmployeeId,
            'date' => $validated['date'],
            'message' => $validated['message'],
        ]);

        $this->notificationService->sendToUser(
            $toEmployeeId,
            '🚪 Aviso en la puerta',
            $validated['message']
        );

        event(new \App\Events\DoorNoticeCreated($tenantId, $toEmployeeId, $fromUser->id, $validated['message']));

        return response()->json(['success' => true]);
    }

    /**
     * Calificación en Pase de Lista (Presentación/Imagen/Energía) — estado #8.
     */
    public function submitPaseListaRatings(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'ratings' => 'required|array|min:1',
            'ratings.*.employee_id' => 'required|integer|exists:users,id',
            'ratings.*.presentacion' => 'required|integer|min:1|max:5',
            'ratings.*.imagen' => 'required|integer|min:1|max:5',
            'ratings.*.energia' => 'required|integer|min:1|max:5',
            'store_id' => 'nullable|integer',
        ]);

        // Anti-tenant-spoofing: los empleados calificados deben pertenecer al mismo tenant.
        $employeeIds = array_column($validated['ratings'], 'employee_id');
        $foreignEmployee = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $employeeIds)
            ->where('tenant_id', '!=', auth()->user()->tenant_id)
            ->exists();

        if ($foreignEmployee) {
            return response()->json([
                'success' => false,
                'message' => 'Uno o más empleados no pertenecen a tu empresa.'
            ], 403);
        }

        try {
            $result = $this->openingService->submitPaseListaRatings(
                auth()->id(),
                $validated['date'],
                $validated['ratings'],
                $validated['store_id'] ?? 1
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }

    /**
     * Checklist de Cierre Seguro (luces, caja fuerte, alarma) antes de registrar salida.
     */
    public function closingChecklist(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'checks' => 'required|array',
            'checks.lights_off' => 'required|boolean',
            'checks.safe_secured' => 'required|boolean',
            'checks.alarm_activated' => 'required|boolean',
            'store_id' => 'nullable|integer',
        ]);

        $targetUser = \App\Models\User::find($validated['user_id']);
        if (!$targetUser || $targetUser->tenant_id !== auth()->user()->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado: el usuario no pertenece a tu empresa.'
            ], 403);
        }

        try {
            $result = $this->openingService->submitClosingChecklist(
                $validated['user_id'],
                $validated['checks'],
                $validated['store_id'] ?? 1
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Apertura de Emergencia con co-validación de 2 testigos presenciales (PIN).
     */
    public function emergencyOpen(Request $request)
    {
        $validated = $request->validate([
            'requester_id' => 'required|integer|exists:users,id',
            'witness_1_id' => 'required|integer|exists:users,id',
            'witness_1_pin' => 'required|string',
            'witness_2_id' => 'required|integer|exists:users,id',
            'witness_2_pin' => 'required|string',
            'store_id' => 'nullable|integer',
        ]);

        $requester = \App\Models\User::find($validated['requester_id']);
        if (!$requester || $requester->tenant_id !== auth()->user()->tenant_id) {
            return response()->json([
                'success' => false,
                'message' => 'Acceso denegado: el solicitante no pertenece a tu empresa.'
            ], 403);
        }

        try {
            $result = $this->openingService->emergencyOpenWithWitnesses(
                $validated['requester_id'],
                $validated['witness_1_id'],
                $validated['witness_1_pin'],
                $validated['witness_2_id'],
                $validated['witness_2_pin'],
                $validated['store_id'] ?? 1
            );
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Report store still closed past official schedule to request amnesty.
     */
    public function reportStoreStillClosed(Request $request)
    {
        $user = Auth::user();
        $storeId = $request->input('store_id', 1);
        $simTime = $request->input('simTime');

        try {
            $result = $this->openingService->reportStoreStillClosed($user->id, $storeId, $simTime);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
