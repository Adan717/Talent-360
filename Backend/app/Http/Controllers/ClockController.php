<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\TimeEntry;
use App\Models\StoreLog;
use App\Models\Contingency;
use App\Models\InternalMessage;
use App\Models\AuditLog;
use App\Models\RoleClockPolicy;
use App\Services\ClockService;
use App\Services\SimulatorSessionService;

/**
 * RECONCILIACIÓN merge/reloj-v2 (F3): sync endurecido de la línea del Reloj (aforo R65, meal_swap
 * R103, switches R93, guard R102, delegación al processPunch server-side) + Simulador Matrix,
 * archivo histórico y CRUD de usuarios de la línea §1–§42.
 */
class ClockController extends Controller
{
    protected $simulatorSessionService;

    public function __construct(SimulatorSessionService $simulatorSessionService)
    {
        $this->simulatorSessionService = $simulatorSessionService;
    }

    /**
     * Resuelve el tenant sobre el que operan los endpoints de reset/purga.
     * Un usuario de tenant usa su propio tenant_id; un platform_admin (sin tenant
     * propio) debe indicar explícitamente el tenant vía el parámetro tenant_id.
     * Devuelve null si no se puede determinar (el caller debe responder 422).
     */
    private function resolveResetTenantId(Request $request): ?int
    {
        $tenantId = auth()->user()->tenant_id ?? $request->input('tenant_id');
        // Rechazar null, cadenas vacías, no-numéricos y valores < 1 (los tenant_id
        // reales empiezan en 1). Evita un falso 200 borrando where tenant_id=0.
        if ($tenantId === null || !is_numeric($tenantId) || (int) $tenantId < 1) {
            return null;
        }
        return (int) $tenantId;
    }

    /**
     * ARBITRAJE F3 — Purga del Simulador Matrix (§13): borra SOLO filas con
     * simulation_session_id no nulo (nunca datos reales), opcionalmente acotado a una sesión.
     * Reemplaza el wipe de QA de la línea del Reloj (el flujo de pruebas ahora vive en sesiones
     * simuladas aisladas). Se conserva de la línea del Reloj la resolución ESTRICTA de tenant
     * (sin `?? 1`: un platform_admin sin tenant debe indicarlo; 422 si no resoluble) — el
     * aislamiento cross-tenant de la purga queda garantizado por ambas vías.
     */
    public function resetDb(Request $request)
    {
        $tenantId = $this->resolveResetTenantId($request);
        if ($tenantId === null) {
            return response()->json(['error' => 'No se pudo determinar el tenant a reiniciar. Indica tenant_id.'], 422);
        }

        $sessionId = $request->input('session_id');

        $result = $this->simulatorSessionService->purge($tenantId, $sessionId ? (int) $sessionId : null);

        return response()->json([
            'success' => true,
            'message' => 'Datos de simulación purgados correctamente.',
            'purged_sessions' => $result['purged_sessions'],
            'deleted_rows' => $result['deleted_rows'],
        ]);
    }

    /**
     * Obtiene la sesión activa del Simulador Matrix para el tenant, creándola si no
     * existe. PanelSimulador.tsx la llama al montar.
     */
    public function getActiveSimulatorSession(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $session = $this->simulatorSessionService->getActiveSession($tenantId, auth()->user()->id);

        return response()->json([
            'success' => true,
            'session_id' => $session->id,
            'simulated_date' => $session->simulated_date->format('Y-m-d'),
            'status' => $session->status,
        ]);
    }

    /**
     * Cierra la sesión activa (si existe) y crea una nueva con simulated_date + 1 día.
     * Nunca borra datos, solo avanza de día.
     */
    public function startNewSimulatorSession(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $session = $this->simulatorSessionService->startNewSession($tenantId, auth()->user()->id);

        return response()->json([
            'success' => true,
            'session_id' => $session->id,
            'simulated_date' => $session->simulated_date->format('Y-m-d'),
            'status' => $session->status,
        ]);
    }

    // ⚠️ DEV-ONLY — Archiva los registros del día indicado antes de borrarlos (merge F3: el
    // archivado de la línea §1–§42 + el scope por TENANT de la línea del Reloj — sin el scope,
    // el reset borraba el día de TODAS las empresas).
    public function resetDay(Request $request)
    {
        if (app()->isProduction() && !env('ALLOW_QA_RESET', false)) {
            return response()->json(['error' => 'Este endpoint está deshabilitado en producción.'], 403);
        }

        $tenantId = $this->resolveResetTenantId($request);
        if ($tenantId === null) {
            return response()->json(['error' => 'No se pudo determinar el tenant a reiniciar. Indica tenant_id.'], 422);
        }

        $date       = $request->input('date', now()->format('Y-m-d'));
        $archivedBy = auth()->check() ? auth()->user()->id : null;

        DB::transaction(function () use ($date, $archivedBy, $tenantId) {
            // Archivar time_entries del día antes de borrar masivamente
            $nowStr = now()->toDateTimeString();
            DB::insert("
                INSERT INTO archived_time_entries (
                    original_id, user_id, tenant_id, date, type, time, is_late, late_minutes, details,
                    employee_name_at_time, job_role_title_at_time, base_salary_at_time,
                    archived_reason, archived_by_user_id, original_created_at, created_at, updated_at
                )
                SELECT
                    id, user_id, tenant_id, date, type, time, is_late, late_minutes, details,
                    employee_name_at_time, job_role_title_at_time, base_salary_at_time,
                    'reset_day', :archived_by, created_at, :created_at, :updated_at
                FROM time_entries
                WHERE DATE(created_at) = :date AND tenant_id = :tenant_id
            ", [
                'archived_by' => $archivedBy,
                'date' => $date,
                'tenant_id' => $tenantId,
                'created_at' => $nowStr,
                'updated_at' => $nowStr
            ]);

            DB::table('time_entries')->where('tenant_id', $tenantId)->whereDate('created_at', $date)->delete();
            DB::table('store_logs')->where('tenant_id', $tenantId)->where('date', $date)->delete();
            DB::table('contingencies')->where('tenant_id', $tenantId)->whereDate('created_at', $date)->delete();
            DB::table('audit_logs')->where('tenant_id', $tenantId)->where('date', $date)->delete();
        });

        return response()->json(['message' => "Datos de la jornada del {$date} archivados y eliminados con éxito."]);
    }

    public function getState(Request $request)
    {
        if (!auth()->check()) {
            return response()->json(['error' => 'Unauthenticated.'], 401);
        }
        $tenantId = auth()->user()->tenant_id;

        // Optimización (§1–§42): limitar registros históricos masivos a la última semana.
        $oneWeekAgo = now()->subDays(7)->format('Y-m-d');

        // Soporte para la Matrix QA (§13): si se pasa simulation_session_id (o 'active'),
        // consultar los datos de esa sesión simulada en lugar de los reales.
        $simSessionId = $request->query('simulation_session_id') ?? $request->input('simulation_session_id');
        if ($simSessionId === 'active') {
            $activeSession = DB::table('simulator_sessions')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->orderBy('id', 'desc')
                ->first();
            $simSessionId = $activeSession?->id;
        } elseif ($simSessionId !== null) {
            $simSessionId = (int) $simSessionId;
        }

        $applySimFilter = function ($query, $column = 'simulation_session_id') use ($simSessionId) {
            if ($simSessionId) {
                return $query->where($column, $simSessionId);
            }
            return $query->whereNull($column);
        };

        $timeEntries = $applySimFilter(
            DB::table('time_entries')
                ->where('tenant_id', $tenantId)
                ->whereDate('date', '>=', $oneWeekAgo)
        )->get();

        $storeLogs = $applySimFilter(
            DB::table('store_logs')
                ->where('tenant_id', $tenantId)
                ->whereDate('date', '>=', $oneWeekAgo)
        )->orderBy('id', 'desc')->limit(50)->get();

        $contingencies = $applySimFilter(
            DB::table('contingencies')
                ->where('tenant_id', $tenantId)
                ->whereDate('created_at', '>=', $oneWeekAgo)
        )->get();

        $messages = $applySimFilter(
            DB::table('internal_messages')
                ->where('tenant_id', $tenantId)
                ->whereDate('created_at', '>=', $oneWeekAgo)
        )->get();

        $auditLogs = $applySimFilter(
            DB::table('audit_logs')
                ->where('tenant_id', $tenantId)
                ->whereDate('date', '>=', $oneWeekAgo)
        )->get();

        // Get permissions per user (Optimized to avoid N+1 queries)
        // `has_completed_induction` vive en `users`, NO en `employees`. Sin este join el payload la
        // omitía → el front la leía como `undefined` → `false`, y como hace `{...currentUser, ...me}`
        // (useAppStore:452) ese false FANTASMA pisaba el valor bueno que /me sí traía.
        $employees = DB::table('employees')
            ->leftJoin('users', 'users.id', '=', 'employees.user_id')
            ->where('employees.tenant_id', $tenantId)
            ->select('employees.*', 'users.has_completed_induction')
            ->get();
        $users = $employees->map(function ($e) {
            // R54: `select('employees.*')` es query builder → el `$hidden` del modelo Employee NO
            // aplica, así que el hash y el blind index del PIN de kiosko viajarían CRUDOS a cada
            // empleado en /sync/state (misma clase de fuga que R44/R50). Se quitan a mano; ídem
            // security_pin (testigos/Silla, línea §1–§42).
            unset($e->kiosk_pin_hash, $e->kiosk_pin_lookup, $e->security_pin);
            // Reloj Checador local client expects the primary ID of the employee
            // to match user_id so time entry tracking maps back to users.id
            $e->employee_id = $e->id;
            $e->id = $e->user_id ?? $e->id;
            return $e;
        });

        // §46: datos de configuración casi-estáticos (puestos, permisos, reglas RBAC,
        // políticas de reloj) — cambian solo cuando un admin edita configuración, no en
        // cada ciclo de 60s de cada usuario. Se cachean por tenant con TTL de 5 min como
        // red de seguridad; la invalidación instantánea al editar la hace
        // TenantConfigCache (observers de modelo + llamadas explícitas en los endpoints
        // que escriben por query builder). Ver App\Support\TenantConfigCache.
        //
        // §46 (bis): antes role_permissions se consultaba dos veces — una con JOIN a
        // permissions (agrupado por puesto con el NOMBRE del permiso) y otra cruda para
        // la respuesta. Ahora se leen ambas tablas una vez y se arman las dos formas.
        $staticConfig = \App\Support\TenantConfigCache::remember($tenantId, function () use ($tenantId) {
            $permissions = DB::table('permissions')->where('tenant_id', $tenantId)->get();
            $rolePermissions = DB::table('role_permissions')->where('tenant_id', $tenantId)->get();

            $permissionNameById = $permissions->pluck('name', 'id');
            $permissionsByRole = $rolePermissions
                ->groupBy('job_role_id')
                ->map(function ($rows) use ($permissionNameById) {
                    return $rows
                        ->map(fn ($rp) => $permissionNameById->get($rp->permission_id))
                        ->filter()
                        ->values();
                });

            return [
                'permissions' => $permissions,
                'role_permissions' => $rolePermissions,
                'permissions_by_role' => $permissionsByRole,
                'job_roles' => JobRole::where('tenant_id', $tenantId)->get(),
                'ui_rbac_rules' => DB::table('ui_rbac_rules')->where('tenant_id', $tenantId)->get(),
                'role_clock_policies' => RoleClockPolicy::where('tenant_id', $tenantId)->get(),
            ];
        });

        $permissions = $staticConfig['permissions'] ?? collect([]);
        $rolePermissions = $staticConfig['role_permissions'] ?? collect([]);
        $permissionsByRole = $staticConfig['permissions_by_role'] ?? collect([]);
        $jobRoles = $staticConfig['job_roles'] ?? collect([]);
        $uiRbacRules = $staticConfig['ui_rbac_rules'] ?? collect([]);
        $roleClockPolicies = $staticConfig['role_clock_policies'] ?? collect([]);

        // Si la caché se corrompió o devolvió un __PHP_Incomplete_Class tras un despliegue, la limpiamos y recalculamos
        if (!($permissionsByRole instanceof \Illuminate\Support\Collection)) {
            \App\Support\TenantConfigCache::forget($tenantId);
            $permissionNameById = collect($permissions)->pluck('name', 'id');
            $permissionsByRole = collect($rolePermissions)
                ->groupBy('job_role_id')
                ->map(function ($rows) use ($permissionNameById) {
                    return collect($rows)
                        ->map(fn ($rp) => $permissionNameById->get(is_object($rp) ? $rp->permission_id : ($rp['permission_id'] ?? null)))
                        ->filter()
                        ->values();
                });
        }

        // $userPermissions depende de $employees (dato vivo, NO cacheado) cruzado con la
        // config cacheada, así que se arma fuera del caché.
        $userPermissions = [];
        foreach ($employees as $e) {
            $key = $e->user_id ?? $e->id;
            $userPermissions[$key] = ($permissionsByRole instanceof \Illuminate\Support\Collection)
                ? $permissionsByRole->get($e->job_role_id, collect([]))
                : collect([]);
        }

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

        // Fallback: Si el tenant ya cuenta con puestos de trabajo creados, considerar onboarding completado
        if (!isset($systemSettings['onboarding_completed'])) {
            $hasJobRoles = DB::table('job_roles')->where('tenant_id', $tenantId)->exists();
            if ($hasJobRoles) {
                $systemSettings['onboarding_completed'] = true;
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
        // §36 (línea §1–§42): la URL del video de la lección de Academia ligada a cada tarea viaja
        // en el mismo payload, para que el frontend no la pida aparte antes de iniciar la tarea.
        $tasks = DB::table('tasks')
            ->leftJoin('academy_courses', 'academy_courses.id', '=', 'tasks.academy_lesson_id')
            ->where('tasks.tenant_id', $tenantId)
            ->select('tasks.*', 'academy_courses.video_url as academy_lesson_video_url')
            ->get();
        // §46: antes esto era un N+1 (una consulta a routine_task por cada rutina).
        // Ahora se traen todas las relaciones de una sola vez y se agrupan en memoria.
        $routines = DB::table('routines')->where('tenant_id', $tenantId)->get();
        $routineIds = $routines->pluck('id')->all();
        $taskIdsByRoutine = empty($routineIds)
            ? collect()
            : DB::table('routine_task')
                ->whereIn('routine_id', $routineIds)
                ->get()
                ->groupBy('routine_id')
                ->map(fn ($rows) => $rows->pluck('task_id')->values()->all());
        $routines = $routines->map(function ($r) use ($taskIdsByRoutine) {
            $r->task_ids = json_encode($taskIdsByRoutine->get($r->id, []));
            return $r;
        });
        $assignments = DB::table('task_assignments')->where('tenant_id', $tenantId)->get();

        // 1. Resolve tenant plan and permissions dynamically
        $tenant = auth()->user()->tenant;
        $tenantPlan = 'freemium';
        $allowedModules = ['reloj', 'rrhh', 'operativo'];
        $allowedFeatures = [];

        if ($tenant) {
            $tenant->load('billingPlan');
            $tenantPlan = $tenant->billingPlan ? $tenant->billingPlan->code : ($tenant->plan ?: 'freemium');

            // Forzar plan Enterprise para DecorArte (Tenant ID 1 y 33)
            if ((int)$tenant->id === 1 || (int)$tenant->id === 33) {
                $tenantPlan = 'enterprise';
            }

            // Check all potential modules
            $modulesToCheck = ['reloj', 'rrhh', 'operativo', 'reportes', 'ats', 'academia', 'portal', 'documentos', 'matrix', 'facturacion', 'lft', 'organizacion'];
            $allowedModules = array_values(array_filter($modulesToCheck, function($m) use ($tenant) {
                return $tenant->isModuleUnlocked($m);
            }));

            // Check all potential features
            $featuresToCheck = ['keys_control', 'meal_timers', 'checklists_validation', 'voice_commands'];
            $allowedFeatures = array_values(array_filter($featuresToCheck, function($f) use ($tenant) {
                return $tenant->isFeatureUnlocked($f);
            }));
        }

        // H6 (prueba en vivo 2026-07-29): el backend YA respeta la autorización de entrada
        // tardía aprobada (ClockService deja fichar pese al Retardo Extremo si existe una fila
        // `approved` para el usuario y la fecha), pero el dial nunca conocía ese estado y
        // seguía mostrando "ACCESO BLOQUEADO" — el colaborador autorizado no podía fichar
        // aunque el servidor ya se lo permitía. Se expone la lista de ids autorizados HOY
        // (en la zona del tenant, igual que el resto de lo fechado).
        $hoyTenant = \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for($tenantId))->toDateString();
        $lateAuthorizedUserIds = DB::table('late_authorization_requests')
            ->where('tenant_id', $tenantId)
            ->where('date', $hoyTenant)
            ->where('status', 'approved')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        return response()->json([
            'late_authorized_user_ids' => $lateAuthorizedUserIds,
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

    // Claves que, aun cuando la ruta permite admin+supervisor, solo un ADMIN puede modificar
    // (ej. qué curso destraba el bloqueo por retardos — política de empresa). Línea §1–§42.
    private const ADMIN_ONLY_SETTING_KEYS = ['punctuality_course_id'];

    public function syncSettings(Request $request)
    {
        // Solo admin/supervisor pueden escribir configuración de empresa. La ruta vive en el
        // grupo `role:empleado,...`, así que sin este gate CUALQUIER empleado podía sobrescribir
        // `clockOpConfig` (apagar la geocerca/IP-lock server-side de un plumazo), `active_modules`,
        // etc. — todos los callers legítimos del FE son paneles de admin. Empleado → 403.
        $role = auth()->user()->role ?? '';
        if (!in_array($role, ['admin', 'supervisor', 'platform_admin'], true)) {
            return response()->json(['message' => 'No autorizado para modificar la configuración de la empresa.'], 403);
        }

        $tenantId = auth()->user()->tenant_id ?? 1;
        $isAdmin = in_array($role, ['admin', 'platform_admin'], true);
        $settings = $request->all();

        // Handle single setting format: { key: 'settingName', value: 'settingValue' }
        if (count($settings) === 2 && array_key_exists('key', $settings) && array_key_exists('value', $settings)) {
            $key = $settings['key'];
            $value = $settings['value'];

            if (in_array($key, self::ADMIN_ONLY_SETTING_KEYS) && !$isAdmin) {
                return response()->json(['error' => 'Solo un administrador puede modificar esta configuración.'], 403);
            }

            if ($key === 'punctuality_course_id' && $value !== null && !\App\Models\AcademyCourse::find($value)) {
                return response()->json(['error' => 'El curso seleccionado no existe o no pertenece a tu empresa.'], 422);
            }

            DB::table('system_settings')->updateOrInsert(
                ['key' => $key, 'tenant_id' => $tenantId],
                ['value' => is_string($value) ? $value : json_encode($value), 'updated_at' => now()]
            );
        } else {
            // Handle bulk settings format: { settingName1: 'value1', settingName2: 'value2' }
            foreach ($settings as $key => $value) {
                if (in_array($key, self::ADMIN_ONLY_SETTING_KEYS) && !$isAdmin) {
                    return response()->json(['error' => 'Solo un administrador puede modificar esta configuración.'], 403);
                }
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
        if (app()->isProduction() && !env('ALLOW_QA_RESET', false)) {
            return response()->json(['error' => 'Este endpoint está deshabilitado en producción.'], 403);
        }

        // NOTA (Ronda 1): a diferencia de resetDb/resetDay, initDb NO se acotó por
        // tenant en esta ronda (colisiona con constraints UNIQUE globales al reinsertar
        // catálogos). DEV-ONLY, platform_admin, ALLOW_QA_RESET-gated, sin llamadores hoy.
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

        // §59: validar que el usuario pertenece al tenant del emisor, resolviendo SIN
        // scope global y comparando de forma explícita/casteada (no depender del scope).
        // (Converge con el fix cross-tenant de la línea del Reloj; ésta además loggea.)
        $targetUser = User::withoutGlobalScopes()->find($userId);
        if (!$targetUser || (int) $targetUser->tenant_id !== (int) $tenantId) {
            \App\Helpers\SecurityLogger::log(
                'tenant_isolation_violation',
                "Intento de fichaje (/sync/clock) sobre user_id={$userId} (tenant " . ($targetUser->tenant_id ?? 'null') . ") desde tenant {$tenantId}",
                $tenantId,
                auth()->id()
            );
            return response()->json(['error' => 'Usuario no encontrado o no pertenece a su empresa.'], 403);
        }

        $type = $request->input('type');

        // Normalizar details a array (el frontend manda string suelto o JSON string).
        $rawDetails = $request->input('details');
        if (is_array($rawDetails)) {
            $details = $rawDetails;
        } elseif (is_string($rawDetails) && $rawDetails !== '') {
            $details = json_decode($rawDetails, true) ?? ['note' => $rawDetails];
        } else {
            $details = [];
        }

        // Escape SÓLO de tests: `sandbox_bypass` salta el IP-lock de sucursal en ClockService (el FE
        // NUNCA lo manda). Se scrubbea igual que en TimeEntryController::punch y KioskController::punch
        // — sin esto, un empleado de plan free con IP-lock fichaba desde casa mandando el flag.
        unset($details['sandbox_bypass']);
        // R88: `via_kiosk` (exime el checklist de cierre) sólo lo marca el KioskController.
        unset($details['via_kiosk']);

        // Ponche de asistencia real: delegar a ClockService::processPunch para que el
        // is_late y las reglas (festivo, tienda cerrada, tolerancia LFT, amnistía, IP lock,
        // secuencia §15, nómina cerrada) se resuelvan en el SERVIDOR, ignorando el is_late
        // del cliente. (La versión previa de esta línea insertaba crudo confiando en el cliente.)
        if (!in_array($type, ClockService::AUXILIARY_ENTRY_TYPES, true)) {
            // Override server-side del bloqueo de Retardo Extremo: solo un admin/supervisor
            // autenticado puede saltarlo (se sobrescribe cualquier valor del cliente).
            $details['supervisor_override'] = in_array(auth()->user()->role ?? '', ['admin', 'supervisor'], true);

            // Normalizar la hora a H:i:s; algunos llamadores mandan formato de display
            // ("10:05 am"). Si no se puede interpretar, se pasa null (processPunch usa la
            // hora del servidor).
            $normalizedTime = $this->normalizeTimeToHis($request->input('time'));
            try {
                app(ClockService::class)->processPunch($targetUser, $type, $normalizedTime, $details);
            } catch (\Exception $e) {
                // Mismo contrato de error que TimeEntryController::punch (otro cliente de
                // processPunch): 400 con {success:false, message}.
                return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
            }
            event(new \App\Events\MonitorUpdated($tenantId));
            return response()->json(['message' => 'Clock synced.']);
        }

        // Registro auxiliar (reserva/cancelación/intercambio de comida): no es asistencia, se
        // inserta tal cual y nunca es 'late'.
        $auxRow = [
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'date' => $request->input('date'),
            'type' => $type,
            'time' => $request->input('time'),
            'is_late' => false,
            'late_minutes' => 0,
            'details' => $rawDetails === null ? null : (is_string($rawDetails) ? $rawDetails : json_encode($details)),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Aforo de comida SERVER-SIDE (R65): la reserva corre en una TRANSACCIÓN con un advisory
        // lock por (tenant, fecha) para que "contar y luego insertar" sea atómico (TOCTOU).
        if ($type === 'meal_reservation') {
            // R93 (D2): con el switch de slots APAGADO, la reserva se rechaza también server-side.
            // `meal_cancel` NO se gatea: liberar una silla siempre es válido.
            if (!ClockService::dialerFeatureEnabled($tenantId, 'enable_meal_slots')) {
                return response()->json([
                    'success' => false,
                    'message' => 'La reserva de comedor está deshabilitada para esta sucursal.',
                ], 409);
            }
            $conflict = DB::transaction(function () use ($tenantId, $targetUser, $request, $auxRow) {
                $this->lockMealDay($tenantId, (string) $request->input('date'));
                $reason = app(ClockService::class)->mealReservationConflict(
                    $targetUser, $request->input('date'), $request->input('time')
                );
                if ($reason !== null) {
                    return $reason;
                }
                DB::table('time_entries')->insert($auxRow);
                return null;
            });
            if ($conflict !== null) {
                return response()->json(['success' => false, 'message' => $conflict], 409);
            }
            event(new \App\Events\MonitorUpdated($tenantId));
            return response()->json(['message' => 'Clock synced.']);
        }

        // R103 (spec §5.3): Intercambiar Comida validado SERVER-SIDE. Reglas: contraparte
        // estructurada (`swap_with`), mismo tenant, MISMO job_role_id de EXPEDIENTE, y el actor
        // es parte del swap o un mando (patrón R87/R102).
        if ($type === 'meal_swap') {
            $actor = auth()->user();
            $swapWith = $request->input('swap_with');
            if (!$swapWith || !is_numeric($swapWith)) {
                return response()->json(['success' => false, 'message' => 'Falta la contraparte del intercambio (swap_with).'], 422);
            }
            if (!in_array((int) $actor->id, [(int) $userId, (int) $swapWith], true)
                && !in_array($actor->role ?? '', ['admin', 'supervisor'], true)) {
                return response()->json(['success' => false, 'message' => 'Sólo los intercambiantes (o un mando) pueden registrar el swap.'], 403);
            }
            $expedientes = DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->whereIn('user_id', [(int) $userId, (int) $swapWith])
                ->pluck('job_role_id', 'user_id');
            if (!$expedientes->has((int) $userId) || !$expedientes->has((int) $swapWith)) {
                return response()->json(['success' => false, 'message' => 'Ambos intercambiantes necesitan expediente en tu empresa.'], 422);
            }
            $rolA = $expedientes[(int) $userId];
            $rolB = $expedientes[(int) $swapWith];
            if ($rolA === null || $rolB === null || (int) $rolA !== (int) $rolB) {
                return response()->json(['success' => false, 'message' => 'Sólo puedes intercambiar comida con compañeros de tu mismo puesto.'], 422);
            }
        }

        DB::table('time_entries')->insert($auxRow);

        event(new \App\Events\MonitorUpdated($tenantId));

        return response()->json(['message' => 'Clock synced.']);
    }

    /**
     * Serializa a los reservantes concurrentes del mismo (tenant, fecha) con un advisory lock de
     * transacción de Postgres. El aforo de comida es una restricción AGREGADA (nº por bloque), no
     * expresable como un unique de columna. En SQLite (tests) es no-op.
     */
    private function lockMealDay(int $tenantId, string $date): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', [$tenantId . ':' . $date]);
        }
    }

    /**
     * Normaliza una hora a formato H:i:s. Acepta H:i:s, H:i y formatos de display
     * ("10:05 am"). Devuelve null si no se puede interpretar.
     */
    private function normalizeTimeToHis($time): ?string
    {
        if (!is_string($time) || trim($time) === '') {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($time)->format('H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }

    public function syncStoreLog(Request $request)
    {
        // Validación de tipo (línea §1–§42) + resolución de tenant de la línea del Reloj.
        $request->validate([
            'type' => ['required', \Illuminate\Validation\Rule::in(['open', 'close', 'forzosa', 'contingency', 'transfer'])],
        ]);

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

        // §13: un store_log del simulador se liga a la sesión activa (aislado de reportes reales).
        $isSimulator = $request->boolean('is_simulator') || $request->input('is_simulator') === true;
        $simSessionId = null;
        if ($isSimulator) {
            $activeSession = DB::table('simulator_sessions')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->orderBy('id', 'desc')
                ->first();
            $simSessionId = $activeSession?->id;
        }

        $id = DB::table('store_logs')->insertGetId([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'simulation_session_id' => $simSessionId,
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

    // Purga permanente del archivo histórico — SOLO platform_admin (línea §1–§42)
    public function purgeArchive(Request $request)
    {
        $user = auth()->user();

        // Solo platform_admin puede destruir datos históricos permanentemente
        if (($user->system_role ?? '') !== 'platform_admin') {
            return response()->json(['error' => 'Acceso denegado. Solo un administrador de plataforma puede purgar el historial.'], 403);
        }

        $tenantId = $request->input('tenant_id');
        $before   = $request->input('before_date'); // Opcional: purgar solo antes de cierta fecha

        $query = DB::table('archived_time_entries');
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }
        if ($before) {
            $query->where('date', '<', $before);
        }

        $count = $query->count();
        $query->delete();

        return response()->json([
            'message' => "Se purgaron {$count} registros del archivo histórico de forma permanente.",
            'purged_count' => $count,
        ]);
    }

    public function syncContingency(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $userId = $request->input('user_id');

        $targetUser = User::find($userId);
        if (!$targetUser) {
            return response()->json(['error' => 'Usuario no encontrado o no pertenece a su empresa.'], 403);
        }

        // R93 (D2): con AMBOS switches de incidencias apagados, el reporte se rechaza también
        // server-side (antes el toggle sólo escondía el botón del dial).
        if (!ClockService::dialerFeatureEnabled($tenantId, 'allow_employee_incidences')
            && !ClockService::dialerFeatureEnabled($tenantId, 'allow_manager_incidences')) {
            return response()->json([
                'success' => false,
                'message' => 'El reporte de incidencias está deshabilitado para esta sucursal.',
            ], 403);
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

        // R102: `late_entry_unlocked` alimenta el BLOQUEO del dial (punctuality_lockout_count)
        // → un empleado que pudiera insertarlo para OTRO le bloquearía el checador al colega
        // (sabotaje). Sólo el propio usuario o un mando pueden registrarlo para terceros.
        $actor = auth()->user();
        if ($request->input('type') === 'late_entry_unlocked'
            && (int) $userId !== (int) $actor->id
            && !in_array($actor->role ?? '', ['admin', 'supervisor'], true)) {
            return response()->json(['error' => 'No puedes registrar retardos de otro usuario.'], 403);
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

    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        $user->update([
            'is_active' => false
        ]);
        return response()->json(['message' => 'User archived successfully.']);
    }

    public function updateJobRole(Request $request, $id)
    {
        // Endpoint legacy /sync/roles/{id}: delega en el CRUD canónico (JobRoleController::update).
        // Antes escribía campos crudos del request sin validar y con `??` a defaults, así que un
        // payload parcial (p.ej. solo un toggle del reloj checador) pisaba name/area con null
        // (dato corrupto o violación NOT NULL → 500). El canónico valida entradas, respeta
        // `sometimes` (no toca lo ausente), acota los superiores por tenant y previene ciclos.
        return app(JobRoleController::class)->update($request, $id);
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

        // §46: escrituras por query builder (permisos/RBAC) no disparan los observers
        // de modelo — se invalida el caché de config del tenant explícitamente.
        \App\Support\TenantConfigCache::forget($tenantId);

        return response()->json(['message' => 'RBAC synchronized successfully']);
    }

    public function updateRolePolicy(Request $request, $id)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;

        $role = JobRole::findOrFail($id);

        // Upsert atómico (unique job_role_id+tenant_id): en conflicto actualiza SOLO config y
        // updated_at, PRESERVANDO el policy_name (personalizado por seeders/plantillas).
        DB::table('role_clock_policies')->upsert(
            [[
                'job_role_id' => $id,
                'tenant_id' => $tenantId,
                'policy_name' => 'Perfil Personalizado',
                'config' => json_encode($request->all()),
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['job_role_id', 'tenant_id'],
            ['config', 'updated_at']
        );

        // §46: escritura por query builder — invalidar el caché de config del tenant.
        \App\Support\TenantConfigCache::forget($tenantId);

        return response()->json(['message' => 'Política actualizada']);
    }

    public function generateSupervisorQR(Request $request)
    {
        // R81 (seguridad): el emisor DEBE ser admin/supervisor y el token se acuña SIEMPRE a su
        // propio nombre — nunca un `supervisor_id` del payload. Sin el chequeo de rol, un empleado
        // raso podía acuñar un QR "de su admin" y auto-autorizarse labor en feriado (bypass R75/R81).
        $actor = auth()->user();
        if (!$actor || !in_array($actor->role, ['admin', 'supervisor', 'platform_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Sólo un supervisor o administrador puede generar un código de autorización.',
            ], 403);
        }

        $supervisorId = $actor->id;
        $token = 'qr_' . bin2hex(random_bytes(16));
        $expiresAt = now()->addSeconds(60);

        DB::table('supervisor_qr_tokens')->insert([
            'supervisor_id' => $supervisorId,
            'token' => $token,
            'expires_at' => $expiresAt,
            'is_used' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String()
        ]);
    }

    public function validateSupervisorQR(Request $request)
    {
        $token = $request->input('token');
        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Token no provisto.'], 400);
        }

        $row = DB::table('supervisor_qr_tokens')
            ->where('token', $token)
            ->first();

        if (!$row) {
            return response()->json(['success' => false, 'message' => 'Token inválido o inexistente.'], 404);
        }

        if ($row->is_used) {
            return response()->json(['success' => false, 'message' => 'El código QR ya ha sido utilizado.'], 400);
        }

        $expiresAt = \Carbon\Carbon::parse($row->expires_at);
        if ($expiresAt->isPast()) {
            return response()->json(['success' => false, 'message' => 'El código QR ha expirado (límite 60s).'], 400);
        }

        DB::table('supervisor_qr_tokens')
            ->where('id', $row->id)
            ->update(['is_used' => true, 'updated_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => 'Autorización de supervisor válida.'
        ]);
    }
}
