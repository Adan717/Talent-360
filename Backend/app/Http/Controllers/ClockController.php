<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\JobRole;
use App\Models\TimeEntry;
use App\Models\StoreLog;
use App\Models\Contingency;
use App\Models\InternalMessage;
use App\Models\AuditLog;
use App\Models\RoleClockPolicy;

class ClockController extends Controller
{
    // ⚠️ DEV-ONLY — No usar en producción
    public function resetDb()
    {
        if (app()->isProduction()) {
            return response()->json(['error' => 'Este endpoint está deshabilitado en producción.'], 403);
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF;');
        } else {
            DB::statement('SET session_replication_role = replica;');
        }

        DB::table('time_entries')->truncate();
        DB::table('store_logs')->truncate();
        DB::table('audit_logs')->truncate();
        DB::table('contingencies')->truncate();
        DB::table('internal_messages')->truncate();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=ON;');
        } else {
            DB::statement('SET session_replication_role = DEFAULT;');
        }

        return response()->json(['message' => 'Database tables truncated.']);
    }

    // ⚠️ DEV-ONLY — No usar en producción
    public function resetDay(Request $request)
    {
        if (app()->isProduction()) {
            return response()->json(['error' => 'Este endpoint está deshabilitado en producción.'], 403);
        }

        $date = $request->input('date', now()->format('Y-m-d'));
        DB::table('time_entries')->whereDate('created_at', $date)->delete();
        DB::table('store_logs')->where('date', $date)->delete();
        DB::table('contingencies')->whereDate('created_at', $date)->delete();
        DB::table('audit_logs')->where('date', $date)->delete();
        return response()->json(['message' => "Datos de la jornada del {$date} eliminados con éxito."]);
    }

    public function getState()
    {
        if (!auth()->check()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        $tenantId = auth()->user()->tenant_id;

        $timeEntries = DB::table('time_entries')->where('tenant_id', $tenantId)->get();
        $storeLogs = DB::table('store_logs')->where('tenant_id', $tenantId)->orderBy('id', 'desc')->get();
        $contingencies = DB::table('contingencies')->where('tenant_id', $tenantId)->get();
        $messages = DB::table('internal_messages')->where('tenant_id', $tenantId)->get();
        $auditLogs = DB::table('audit_logs')->where('tenant_id', $tenantId)->get();

        // Get permissions per user (Optimized to avoid N+1 queries)
        $employees = DB::table('employees')->where('tenant_id', $tenantId)->get();
        $users = $employees->map(function ($e) {
            // Reloj Checador local client expects the primary ID of the employee
            // to match user_id so time entry tracking maps back to users.id
            $e->employee_id = $e->id;
            $e->id = $e->user_id ?? $e->id;
            return $e;
        });

        $rolePermissions = DB::table('role_permissions')
            ->where('role_permissions.tenant_id', $tenantId)
            ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
            ->select('role_permissions.job_role_id', 'permissions.name')
            ->get()
            ->groupBy('job_role_id');

        $userPermissions = [];
        foreach ($employees as $e) {
            $key = $e->user_id ?? $e->id;
            $userPermissions[$key] = $rolePermissions->has($e->job_role_id)
                ? $rolePermissions->get($e->job_role_id)->pluck('name')
                : collect([]);
        }
        $jobRoles = JobRole::where('tenant_id', $tenantId)->get();
        $uiRbacRules = DB::table('ui_rbac_rules')->where('tenant_id', $tenantId)->get();
        $roleClockPolicies = RoleClockPolicy::where('tenant_id', $tenantId)->get();
        $permissions = DB::table('permissions')->where('tenant_id', $tenantId)->get();
        $rolePermissions = DB::table('role_permissions')->where('tenant_id', $tenantId)->get();

        $rawSettings = DB::table('system_settings')
            ->where(function($query) use ($tenantId) {
                $query->where('tenant_id', $tenantId)
                      ->orWhereNull('tenant_id');
            })
            ->orderByRaw('CASE WHEN tenant_id IS NULL THEN 0 ELSE 1 END ASC')
            ->get();
        $systemSettings = [];
        foreach ($rawSettings as $rs) {
            $decoded = json_decode($rs->value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $systemSettings[$rs->key] = $decoded;
            } else {
                $systemSettings[$rs->key] = $rs->value;
            }
        }
        
        // Calculate activeEncargadoId based on yesterday's check_out details
        $lastDelegation = DB::table('time_entries')
            ->where('tenant_id', $tenantId)
            ->where('type', 'check_out')
            ->whereNotNull('details')
            ->orderBy('id', 'desc')
            ->first();
        
        // Find default admin user for this tenant to avoid hardcoded ID 1
        $defaultAdmin = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('role', 'admin')
            ->orderBy('id', 'asc')
            ->first();
        $activeEncargadoId = $defaultAdmin ? $defaultAdmin->id : 1;

        if ($lastDelegation) {
            $details = json_decode($lastDelegation->details, true);
            if (isset($details['delegatedTo'])) {
                $activeEncargadoId = $details['delegatedTo'];
            }
        }
        $tasks = DB::table('tasks')->where('tenant_id', $tenantId)->get();
        $routines = DB::table('routines')->where('tenant_id', $tenantId)->get();
        $assignments = DB::table('task_assignments')->where('tenant_id', $tenantId)->get();
 
        // 1. Resolve tenant plan and permissions dynamically
        $tenant = auth()->user()->tenant;
        $tenantPlan = 'freemium';
        $allowedModules = ['reloj', 'rrhh', 'operativo'];
        $allowedFeatures = [];
 
        if ($tenant) {
            $tenant->load('billingPlan');
            $tenantPlan = $tenant->billingPlan ? $tenant->billingPlan->code : ($tenant->plan ?: 'freemium');
            
            // Forzar plan Enterprise para DecorArte (Tenant ID 1)
            if ((int)$tenant->id === 1) {
                $tenantPlan = 'enterprise';
            }
            
            // Check all potential modules
            $modulesToCheck = ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'portal', 'documentos'];
            $allowedModules = array_values(array_filter($modulesToCheck, function($m) use ($tenant) {
                return $tenant->isModuleUnlocked($m);
            }));
 
            // Check all potential features
            $featuresToCheck = ['keys_control', 'meal_timers', 'checklists_validation', 'voice_commands'];
            $allowedFeatures = array_values(array_filter($featuresToCheck, function($f) use ($tenant) {
                return $tenant->isFeatureUnlocked($f);
            }));
        }
 
        return response()->json([
            'time_entries' => $timeEntries,
            'store_logs' => $storeLogs,
            'contingencies' => $contingencies,
            'internal_messages' => $messages,
            'audit_logs' => $auditLogs,
            'user_permissions' => $userPermissions,
            'users' => $users,
            'job_roles' => $jobRoles,
            'ui_rbac_rules' => $uiRbacRules,
            'role_clock_policies' => $roleClockPolicies,
            'permissions' => $permissions,
            'role_permissions' => $rolePermissions,
            'system_settings' => $systemSettings,
            'active_encargado_id' => $activeEncargadoId,
            'tasks' => $tasks,
            'routines' => $routines,
            'assignments' => $assignments,
            'tenant_plan' => $tenantPlan,
            'tenant_allowed_modules' => $allowedModules,
            'tenant_allowed_features' => $allowedFeatures
        ]);
    }

    public function syncSettings(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $settings = $request->all();
        
        // Handle single setting format: { key: 'settingName', value: 'settingValue' }
        if (count($settings) === 2 && array_key_exists('key', $settings) && array_key_exists('value', $settings)) {
            $key = $settings['key'];
            $value = $settings['value'];
            DB::table('system_settings')->updateOrInsert(
                ['key' => $key, 'tenant_id' => $tenantId],
                ['value' => is_string($value) ? $value : json_encode($value), 'updated_at' => now()]
            );
        } else {
            // Handle bulk settings format: { settingName1: 'value1', settingName2: 'value2' }
            foreach ($settings as $key => $value) {
                DB::table('system_settings')->updateOrInsert(
                    ['key' => $key, 'tenant_id' => $tenantId],
                    ['value' => is_string($value) ? $value : json_encode($value), 'updated_at' => now()]
                );
            }
        }
        return response()->json(['message' => 'Settings updated successfully']);
    }

    // ⚠️ DEV-ONLY — No usar en producción
    public function initDb(Request $request)
    {
        if (app()->isProduction()) {
            return response()->json(['error' => 'Este endpoint está deshabilitado en producción.'], 403);
        }

        $users = $request->input('users');
        $configs = $request->input('configs');
        $tenantId = auth()->user()->tenant_id ?? 1;

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF;');
        } else {
            DB::statement('SET session_replication_role = replica;');
        }

        DB::table('employees')->truncate();
        DB::table('users')->where('role', 'empleado')->delete();
        DB::table('role_permissions')->truncate();
        DB::table('permissions')->truncate();
        DB::table('job_roles')->truncate();

        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=ON;');
        } else {
            DB::statement('SET session_replication_role = DEFAULT;');
        }

        // Define permissions
        $perms = [
            ['name' => 'view_reports', 'description' => 'Ver tablas de reportes y excel'],
            ['name' => 'manage_contingencies', 'description' => 'Aprobar justificantes y otorgar amnistía'],
            ['name' => 'broadcast_messages', 'description' => 'Mandar mensajes por megáfono'],
            ['name' => 'open_store', 'description' => 'Abrir y cerrar la tienda (Cierre Maestro)'],
            ['name' => 'manage_tasks', 'description' => 'Generar y asignar tareas a colaboradores'],
            ['name' => 'roll_call', 'description' => 'Pasar lista de asistencia (Entradas/Salidas)'],
            ['name' => 'take_breaks', 'description' => 'Derecho a tomar descansos y pausas activas']
        ];
        foreach ($perms as $p) {
            $p['id'] = DB::table('permissions')->insertGetId(array_merge($p, ['created_at' => now(), 'updated_at' => now()]));
        }
        $permIds = DB::table('permissions')->pluck('id', 'name');

        // Extract unique roles from users
        $roleMap = [];
        foreach ($users as $u) {
            $roleName = $u['role'] ?? 'Empleado General';
            if (!isset($roleMap[$roleName])) {
                $roleId = DB::table('job_roles')->insertGetId([
                    'name' => $roleName,
                    'area' => 'Ventas',
                    'esAperturador' => in_array($roleName, ['Encargado Titular', 'Segundo Encargado', 'Supervisor']),
                    'portadorLlaves' => in_array($roleName, ['Encargado Titular', 'Segundo Encargado', 'Supervisor']) ? 'ambos' : 'ninguno',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $roleMap[$roleName] = $roleId;

                // Assign permissions based on role
                if (in_array($roleName, ['Encargado Titular', 'Segundo Encargado', 'Supervisor'])) {
                    DB::table('role_permissions')->insert([
                        ['job_role_id' => $roleId, 'permission_id' => $permIds['view_reports']],
                        ['job_role_id' => $roleId, 'permission_id' => $permIds['manage_contingencies']],
                        ['job_role_id' => $roleId, 'permission_id' => $permIds['broadcast_messages']],
                        ['job_role_id' => $roleId, 'permission_id' => $permIds['open_store']],
                        ['job_role_id' => $roleId, 'permission_id' => $permIds['manage_tasks']],
                        ['job_role_id' => $roleId, 'permission_id' => $permIds['roll_call']],
                        ['job_role_id' => $roleId, 'permission_id' => $permIds['take_breaks']],
                    ]);
                } else {
                    DB::table('role_permissions')->insert([
                        ['job_role_id' => $roleId, 'permission_id' => $permIds['manage_tasks']],
                        ['job_role_id' => $roleId, 'permission_id' => $permIds['roll_call']],
                        ['job_role_id' => $roleId, 'permission_id' => $permIds['take_breaks']],
                    ]);
                }
            }
        }

        foreach ($users as $u) {
            $conf = $configs[$u['id']] ?? [];
            
            // Insert into users for login access
            $userId = DB::table('users')->insertGetId([
                'name' => $u['name'],
                'email' => strtolower(str_replace(' ', '.', $u['name'])) . '@talent360.com',
                'password' => Hash::make('123456'),
                'role' => 'empleado',
                'tenant_id' => $tenantId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Insert into employees for operational data
            DB::table('employees')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'name' => $u['name'],
                'email' => strtolower(str_replace(' ', '.', $u['name'])) . '@talent360.com',
                'job_role_id' => $roleMap[$u['role'] ?? 'Empleado General'],
                'shiftStart' => $conf['start'] ?? '08:00:00',
                'shiftEnd' => $conf['end'] ?? '18:00:00',
                'mealMinutes' => 60,
                'restDay' => $conf['restDay'] ?? 'Domingo',
                'is_active_employee' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return response()->json(['message' => 'DB initialized with employees.']);
    }

    public function sync(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $userId = $request->input('user_id');

        // Validar que el usuario pertenece al tenant del emisor
        $targetUser = User::find($userId);
        if (!$targetUser) {
            return response()->json(['error' => 'Usuario no encontrado o no pertenece a su empresa.'], 403);
        }

        $date = $request->input('date');
        $type = $request->input('type');
        $time = $request->input('time');
        $isLate = $request->input('is_late', false);
        $lateMinutes = $request->input('late_minutes', 0);
        $details = $request->input('details');

        DB::table('time_entries')->insert([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'date' => $date,
            'type' => $type,
            'time' => $time,
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
            'details' => $details,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($isLate) {
            DB::table('audit_logs')->insert([
                'user_id' => $userId,
                'tenant_id' => $tenantId,
                'date' => $date,
                'type' => 'late',
                'timestamp_str' => "$date $time",
                'reason' => "Llegada tarde por $lateMinutes min.",
                'punishment_amount' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        event(new \App\Events\MonitorUpdated($tenantId));

        return response()->json(['message' => 'Clock synced.']);
    }

    public function syncStoreLog(Request $request)
    {
        $userId = $request->input('user_id') ?? (auth()->check() ? auth()->user()->id : null);
        $tenantId = auth()->check() ? auth()->user()->tenant_id : null;
        if (!$tenantId && $userId) {
            $tenantId = DB::table('users')->where('id', $userId)->value('tenant_id');
        }

        if ($userId) {
            $targetUser = User::find($userId);
            if (!$targetUser) {
                return response()->json(['error' => 'Usuario no autorizado.'], 403);
            }
        }

        $type = $request->input('type');
        $id = DB::table('store_logs')->insertGetId([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'date' => $request->input('date', now()->format('Y-m-d')),
            'type' => $type,
            'time' => $request->input('time', now()->format('H:i:s')),
            'notes' => $request->input('notes'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($type === 'open' && $tenantId) {
            $userIds = DB::table('users')->where('tenant_id', $tenantId)->pluck('id')->toArray();
            event(new \App\Events\StoreOpened($tenantId, $userIds));
        }

        return response()->json(['message' => 'Store log synced.', 'id' => $id]);
    }

    public function syncContingency(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $userId = $request->input('user_id');

        $targetUser = User::find($userId);
        if (!$targetUser) {
            return response()->json(['error' => 'Usuario no encontrado o no pertenece a su empresa.'], 403);
        }

        $id = DB::table('contingencies')->insertGetId([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'type' => $request->input('type'),
            'status' => $request->input('status', 'pending'),
            'justification_text' => $request->input('justification_text'),
            'resolved_by' => $request->input('resolved_by'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Contingency synced.', 'id' => $id]);
    }

    public function syncMessage(Request $request)
    {
        $request->validate([
            'sender_id' => 'required|integer',
            'receiver_id' => 'nullable|integer',
            'type' => 'nullable|string',
            'content' => 'required|string',
        ]);

        $user = auth()->user() ?? auth('sanctum')->user();
        $tenantId = $user ? $user->tenant_id : 1;

        // Verify sender belongs to active tenant
        $senderExists = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('id', $request->input('sender_id'))
            ->where('tenant_id', $tenantId)
            ->exists();

        if (!$senderExists) {
            return response()->json(['error' => 'El remitente no pertenece a su organización.'], 403);
        }

        // Verify receiver belongs to active tenant if specified
        $receiverId = $request->input('receiver_id');
        if ($receiverId) {
            $receiverExists = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
                ->where('id', $receiverId)
                ->where('tenant_id', $tenantId)
                ->exists();

            if (!$receiverExists) {
                return response()->json(['error' => 'El destinatario no pertenece a su organización.'], 403);
            }
        }

        $id = DB::table('internal_messages')->insertGetId([
            'sender_id' => $request->input('sender_id'),
            'receiver_id' => $receiverId,
            'type' => $request->input('type', 'private'),
            'content' => $request->input('content'),
            'tenant_id' => $tenantId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Message synced.', 'id' => $id]);
    }

    public function syncAuditLog(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $userId = $request->input('user_id');

        $targetUser = User::find($userId);
        if (!$targetUser) {
            return response()->json(['error' => 'Usuario no encontrado o no pertenece a su empresa.'], 403);
        }

        $id = DB::table('audit_logs')->insertGetId([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'date' => $request->input('date', now()->format('Y-m-d')),
            'type' => $request->input('type'),
            'timestamp_str' => $request->input('timestamp_str'),
            'reason' => $request->input('reason'),
            'punishment_amount' => $request->input('punishment_amount', 0),
            'details' => $request->input('details'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'Audit log synced.', 'id' => $id]);
    }

    public function createUser(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'job_role_id' => 'required|integer',
            'email' => 'nullable|email'
        ]);

        $role = JobRole::find($validated['job_role_id']);
        if (!$role) {
            return response()->json(['error' => 'Puesto no encontrado o no pertenece a su empresa.'], 403);
        }

        try {
            DB::beginTransaction();

            $email = $validated['email'] ?? strtolower(str_replace(' ', '.', $validated['name'])) . '@talent360.com';
            $userId = DB::table('users')->insertGetId([
                'name' => $validated['name'],
                'email' => $email,
                'password' => Hash::make('123456'),
                'role' => 'empleado',
                'tenant_id' => $tenantId,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::table('employees')->insert([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'name' => $validated['name'],
                'email' => $email,
                'job_role_id' => $validated['job_role_id'],
                'is_active_employee' => true,
                'shiftStart' => '08:00:00',
                'shiftEnd' => '18:00:00',
                'mealMinutes' => 60,
                'restDay' => 'Domingo',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::commit();
            return response()->json(['message' => 'Created', 'id' => $userId]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateUser(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'job_role_id' => 'required|integer',
            'shiftStart' => 'nullable|string',
            'shiftEnd' => 'nullable|string',
            'mealMinutes' => 'nullable|integer',
            'restDay' => 'nullable|string',
            'portadorLlaves' => 'nullable|string',
            'employee_id' => 'nullable|string',
            'curp' => 'nullable|string',
            'rfc' => 'nullable|string',
            'nss' => 'nullable|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'emergency_contact_name' => 'nullable|string',
            'emergency_contact_phone' => 'nullable|string',
            'hire_date' => 'nullable|date',
            'contract_type' => 'nullable|string',
            'base_salary' => 'nullable|numeric',
            'google_id' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $role = JobRole::find($validated['job_role_id']);
        if (!$role) {
            return response()->json(['error' => 'Puesto no encontrado o no pertenece a su empresa.'], 403);
        }

        try {
            DB::beginTransaction();

            $user = User::findOrFail($id);
            $user->update([
                'name' => $validated['name'],
                'google_id' => $validated['google_id'] ?? $user->google_id,
                'is_active' => $validated['is_active'] ?? $user->is_active,
            ]);

            $employee = Employee::where('user_id', $id)->first();
            if ($employee) {
                $employee->update([
                    'name' => $validated['name'],
                    'job_role_id' => $validated['job_role_id'],
                    'shiftStart' => $validated['shiftStart'] ?? $employee->shiftStart,
                    'shiftEnd' => $validated['shiftEnd'] ?? $employee->shiftEnd,
                    'mealMinutes' => $validated['mealMinutes'] ?? $employee->mealMinutes,
                    'restDay' => $validated['restDay'] ?? $employee->restDay,
                    'portadorLlaves' => $validated['portadorLlaves'] ?? $employee->portadorLlaves,
                    'employee_id' => $validated['employee_id'] ?? $employee->employee_id,
                    'curp' => $validated['curp'] ?? $employee->curp,
                    'rfc' => $validated['rfc'] ?? $employee->rfc,
                    'nss' => $validated['nss'] ?? $employee->nss,
                    'phone' => $validated['phone'] ?? $employee->phone,
                    'address' => $validated['address'] ?? $employee->address,
                    'emergency_contact_name' => $validated['emergency_contact_name'] ?? $employee->emergency_contact_name,
                    'emergency_contact_phone' => $validated['emergency_contact_phone'] ?? $employee->emergency_contact_phone,
                    'hire_date' => $validated['hire_date'] ?? $employee->hire_date,
                    'contract_type' => $validated['contract_type'] ?? $employee->contract_type,
                    'base_salary' => $validated['base_salary'] ?? $employee->base_salary,
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Updated']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function updateJobRole(Request $request, $id)
    {
        $role = JobRole::findOrFail($id);

        $reports_to_role_ids = $request->reports_to_role_ids;
        $reports_to_role_id = $request->reports_to_role_id;
        
        if (is_array($reports_to_role_ids) && count($reports_to_role_ids) > 0) {
            $reports_to_role_ids = array_map('intval', $reports_to_role_ids);
            $reports_to_role_id = $reports_to_role_ids[0];
            $reports_to_role_ids_json = json_encode($reports_to_role_ids);
        } else {
            $reports_to_role_ids_json = $reports_to_role_id ? json_encode([(int)$reports_to_role_id]) : null;
        }

        $role->update([
            'name' => $request->name,
            'area' => $request->area,
            'description' => $request->description,
            'responsibilities' => $request->responsibilities,
            'reports_to_role_id' => $reports_to_role_id,
            'reports_to_role_ids' => $reports_to_role_ids,
            'late_penalty_multiplier' => $request->late_penalty_multiplier ?? 1,
            'required_equipment' => $request->required_equipment,
            'tiempoTolerancia' => $request->tiempoTolerancia ?? 10,
            'portadorLlaves' => $request->portadorLlaves ?? 'ninguno',
            'requiereJustificante' => $request->requiereJustificante ?? true,
            'puedeEmitirAvisos' => $request->puedeEmitirAvisos ?? false,
            'aplicaLeySilla' => $request->aplicaLeySilla ?? false,
            'evaluacion360Activa' => $request->evaluacion360Activa ?? false,
        ]);

        return response()->json(['message' => 'Role updated']);
    }

    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        $user->update([
            'is_active' => false
        ]);
        return response()->json(['message' => 'User archived successfully.']);
    }

    public function syncRbac(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $rules = $request->input('rules');
        $role_permissions = $request->input('role_permissions');

        if ($rules !== null) {
            DB::table('ui_rbac_rules')->where('tenant_id', $tenantId)->delete();
        }
        if ($role_permissions !== null) {
            DB::table('role_permissions')->where('tenant_id', $tenantId)->delete();
        }

        if (!empty($rules)) {
            $insertData = [];
            foreach ($rules as $r) {
                $roleExists = DB::table('job_roles')->where('id', $r['job_role_id'])->where('tenant_id', $tenantId)->exists();
                if (!$roleExists) continue;

                $insertData[] = [
                    'job_role_id' => $r['job_role_id'],
                    'state' => $r['state'],
                    'module' => $r['module'],
                    'tenant_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
            if (count($insertData) > 0) {
                DB::table('ui_rbac_rules')->insert($insertData);
            }
        }

        if (!empty($role_permissions)) {
            $insertData = [];
            foreach ($role_permissions as $rp) {
                $roleExists = DB::table('job_roles')->where('id', $rp['job_role_id'])->where('tenant_id', $tenantId)->exists();
                if (!$roleExists) continue;

                $insertData[] = [
                    'job_role_id' => $rp['job_role_id'],
                    'permission_id' => $rp['permission_id'],
                    'tenant_id' => $tenantId,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }
            if (count($insertData) > 0) {
                DB::table('role_permissions')->insert($insertData);
            }
        }

        return response()->json(['message' => 'RBAC synchronized successfully']);
    }

    public function updateRolePolicy(Request $request, $id)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $role = JobRole::findOrFail($id);

        $policy = DB::table('role_clock_policies')
            ->where('job_role_id', $id)
            ->where('tenant_id', $tenantId)
            ->first();

        if ($policy) {
            DB::table('role_clock_policies')
                ->where('job_role_id', $id)
                ->where('tenant_id', $tenantId)
                ->update(['config' => json_encode($request->all()), 'updated_at' => now()]);
        } else {
            DB::table('role_clock_policies')->insert([
                'job_role_id' => $id,
                'tenant_id' => $tenantId,
                'policy_name' => 'Perfil Personalizado',
                'config' => json_encode($request->all()),
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
        return response()->json(['message' => 'Política actualizada']);
    }
}
