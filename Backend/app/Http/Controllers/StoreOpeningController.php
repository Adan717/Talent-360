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
use Illuminate\Support\Facades\Auth;

class StoreOpeningController extends Controller
{
    protected $settingsService;
    protected $openingService;
    protected $handoffService;

    public function __construct(
        StoreOpeningSettingsService $settingsService,
        StoreOpeningService $openingService,
        StoreOpeningHandoffService $handoffService
    ) {
        $this->settingsService = $settingsService;
        $this->openingService = $openingService;
        $this->handoffService = $handoffService;
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
            ->with('employee:id,name,email,role')
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

        $validated = $request->validate([
            'employee_id' => 'required|exists:users,id',
            'priority_order' => 'integer|min:1',
            'can_open_store' => 'boolean',
            'can_close_store' => 'boolean',
            'has_keys' => 'boolean',
            'is_active' => 'boolean',
        ]);

        // Evitar duplicados para el mismo empleado
        $exists = StoreOpeningAssignment::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $validated['employee_id'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'El colaborador ya cuenta con una asignación de apertura.'
            ], 422);
        }

        $validated['tenant_id'] = $tenantId;
        $validated['company_id'] = 1;
        $validated['store_id'] = $request->input('store_id', 1);

        $assignment = StoreOpeningAssignment::create($validated);
        
        // Cargar relación
        $assignment->load('employee:id,name,email,role');

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
        $assignment->load('employee:id,name,email,role');

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

        try {
            $result = $this->openingService->openStoreAndClockIn($user->id, $storeId, $simTime);
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

        try {
            $result = $this->handoffService->reportOpeningAbsence($user->id, $storeId, $simTime);
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

        try {
            $result = $this->handoffService->reportOpeningLate(
                $user->id,
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
