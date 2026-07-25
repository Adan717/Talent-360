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
use Illuminate\Validation\Rule;

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
        // store_opening_assignments.employee_id exige como FK.
        // ARBITRAJE F3 — acepta AMBOS protocolos: `user_id` (§30, lo único que el FE conoce con
        // certeza) o `employee_id` (línea del Reloj, el id real de la FK). Cualquiera resuelve al
        // mismo expediente, SIEMPRE acotado al tenant del emisor (la regla `exists` plana ignora
        // el TenantScope, así que el filtro por tenant es lo que evita asignar gente de otra empresa).
        $validated = $request->validate([
            'user_id' => 'required_without:employee_id|integer|exists:users,id',
            'employee_id' => [
                'required_without:user_id',
                Rule::exists('employees', 'id')->where('tenant_id', $tenantId),
            ],
            'priority_order' => 'integer|min:1',
            'can_open_store' => 'boolean',
            'can_close_store' => 'boolean',
            'has_keys' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $employee = \App\Models\Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when(isset($validated['user_id']),
                fn ($q) => $q->where('user_id', $validated['user_id']),
                fn ($q) => $q->where('id', $validated['employee_id']))
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

        // R100 (spec §3/§5 "Llamar a Encargado de Llaves"): el teléfono del responsable de apertura
        // viaja SÓLO a portadores de llaves (asignación ACTIVA con can_open_store). El gate es
        // server-side a propósito: a un empleado común el dato NO le llega.
        $responsiblePhone = null;
        $myEmployeeId = \Illuminate\Support\Facades\DB::table('employees')
            ->where('tenant_id', $tenantId)->where('user_id', $user->id)->value('id');
        $esPortador = $myEmployeeId && \Illuminate\Support\Facades\DB::table('store_opening_assignments')
            ->where('tenant_id', $tenantId)
            ->where('employee_id', $myEmployeeId)
            ->where('is_active', true)
            ->where('can_open_store', true)
            ->exists();
        if ($esPortador && $status && $status->current_responsible_employee_id
            && (int) $status->current_responsible_employee_id !== (int) $user->id) {
            // (si el responsable eres TÚ, no hay a quién llamar — el FE no muestra el botón)
            $responsiblePhone = \Illuminate\Support\Facades\DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $status->current_responsible_employee_id)
                ->value('phone');
        }

        return response()->json([
            'success' => true,
            'status' => $status,
            'responsible_phone' => $responsiblePhone,
            // R47: los ajustes viajan aquí a propósito — el motor del reloj (useClockEngine) YA
            // consulta este endpoint cada 5s y es accesible al empleado, mientras que
            // `GET /store-opening/settings` es admin-only. Sin esto el motor usaba eternamente sus
            // defaults hardcodeados y apagar "requiere checklist" desde el panel no apagaba nada.
            'settings' => $this->settingsService->getOpeningSettings($tenantId, $storeId),
            // R50: ídem con las asignaciones — `GET /store-opening/assignments` es admin-only, así
            // que el empleado recibía 403 y el reloj se quedaba sin saber quién abre (nombre falso
            // "Encargado" + lista de llaves hardcodeada). Van scrubbeadas (sin email ni relación).
            'assignments' => $this->openingService->getAssignmentsForClock($tenantId, $storeId),
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
        $tenantId = $user->tenant_id ?? 1;

        // R93 (D2, merge F3): este flujo es específico del ENCARGADO de apertura (dispara la
        // cascada de delegación de llaves) → lo gatea su switch. Antes apagar el toggle sólo
        // escondía el botón y una petición cruda disparaba la cascada igual.
        if (!\App\Services\ClockService::dialerFeatureEnabled($tenantId, 'allow_manager_incidences')) {
            return response()->json([
                'success' => false,
                'message' => 'El reporte de incidencias del encargado está deshabilitado para esta sucursal.',
            ], 403);
        }

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
    /**
     * ARBITRAJE F3 — Apertura de EMERGENCIA, endpoint de DOBLE protocolo:
     *  a) `pin1`+`pin2` (línea del Reloj, R86): testigos ANÓNIMOS resueltos por su PIN de kiosko
     *     (blind index + bcrypt + rate-limit R54); el actor autenticado abre y ficha.
     *  b) `requester_id`+`witness_*_id`+`witness_*_pin` (línea §1–§42, §37): testigos por id con
     *     su security_pin (co-validación presencial).
     * Ambos exigen 2 personas DISTINTAS del tenant y dejan bitácora/auditoría.
     */
    public function emergencyOpen(Request $request)
    {
        if ($request->filled('pin1') || $request->filled('pin2')) {
            return $this->emergencyOpenByKioskPins($request);
        }

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
     * Protocolo (a) del emergencyOpen: 2 PINs de kiosko (R86). El suplente presente teclea los
     * PINs de 2 compañeros; se validan server-side (R54) y, si ambos son de empleados DISTINTOS,
     * activos, del tenant y distintos del actor, se abre la tienda con alerta a los mandos.
     */
    private function emergencyOpenByKioskPins(Request $request)
    {
        $request->validate([
            'pin1' => ['required', 'string', 'regex:/\A\d{6}\z/'],
            'pin2' => ['required', 'string', 'regex:/\A\d{6}\z/'],
        ]);

        $actor = Auth::user();
        $tenantId = $actor->tenant_id;
        if ($tenantId === null) {
            return response()->json(['success' => false, 'message' => 'Sin tenant.'], 403);
        }

        // El actor debe poder FICHAR (expediente con puesto): abre la tienda Y registra su entrada.
        $actorEmp = \App\Models\Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('user_id', $actor->id)->first();
        if (!$actorEmp || !$actorEmp->canClockIn()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes un expediente con puesto asignado; no puedes abrir la tienda.',
            ], 403);
        }

        // Rate-limit de PINs fallidos (patrón R54/R81): sólo cuentan los FALLOS, key por (tenant, actor).
        $rateKey = 'emergency-open-fail:' . $tenantId . ':' . $actor->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($rateKey, 5)) {
            return response()->json([
                'success' => false,
                'message' => 'Demasiados intentos fallidos. Espera un momento e inténtalo de nuevo.',
            ], 429);
        }

        // Dos PINs distintos de entrada (si son iguales, jamás serían 2 testigos).
        if ($request->pin1 === $request->pin2) {
            \Illuminate\Support\Facades\RateLimiter::hit($rateKey, 60);
            return response()->json([
                'success' => false,
                'message' => 'Los 2 testigos deben ser personas distintas.',
            ], 422);
        }

        $w1 = $this->resolveWitness($request->pin1, $tenantId, $actor->id);
        $w2 = $this->resolveWitness($request->pin2, $tenantId, $actor->id);

        // Mensaje genérico ante cualquier fallo (anti-oráculo, R81).
        if (!$w1 || !$w2 || (int) $w1->user_id === (int) $w2->user_id) {
            \Illuminate\Support\Facades\RateLimiter::hit($rateKey, 60);
            return response()->json([
                'success' => false,
                'message' => 'No se pudo validar a los 2 testigos. Verifica que sean 2 compañeros distintos y presentes.',
            ], 422);
        }

        try {
            $result = $this->openingService->emergencyOpenStore(
                $actor->id,
                [(int) $w1->user_id, (int) $w2->user_id],
                [$w1->name, $w2->name],
                $request->input('store_id') ? (int) $request->input('store_id') : 1,
                $request->input('simTime')
            );

            // Alerta PRIORITARIA a los mandos del tenant (patrón R80, tenant-scoped).
            app(\App\Services\NotificationService::class)->sendToTenantAdmins(
                $tenantId,
                '🚨 Apertura de EMERGENCIA de la tienda',
                "{$actor->name} abrió la tienda en emergencia, con 2 testigos ({$w1->name} y {$w2->name}).",
                ['type' => 'emergency_store_open']
            );

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    /**
     * Resuelve un testigo por su PIN de kiosko: empleado ACTIVO del tenant, con el PIN correcto, y
     * DISTINTO del actor. Devuelve el Employee o null (mensaje genérico arriba). Espeja KioskController.
     */
    private function resolveWitness(string $pin, int $tenantId, int $actorUserId)
    {
        $lookup = \App\Models\Employee::kioskPinLookup($pin);
        $emp = \App\Models\Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('kiosk_pin_lookup', $lookup)
            ->first();

        if (!$emp || !$emp->kiosk_pin_hash || !\Illuminate\Support\Facades\Hash::check($pin, $emp->kiosk_pin_hash)) {
            return null;
        }
        // El testigo no puede ser el propio actor (co-validación = OTRAS personas).
        if ((int) $emp->user_id === (int) $actorUserId) {
            return null;
        }
        // Expediente ACTIVO (review R86) + usuario ACTIVO del tenant.
        if (!$emp->is_active_employee) {
            return null;
        }
        $u = \App\Models\User::withoutGlobalScopes()->find($emp->user_id);
        if (!$u || (int) $u->tenant_id !== (int) $tenantId || !$u->is_active) {
            return null;
        }

        return $emp;
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
