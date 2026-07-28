<?php

namespace App\Http\Controllers;

use App\Support\PermissionCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * §65: administración de la matriz de capacidades por puesto. SOLO admin (protegido en la
 * ruta con role:admin). Es una capacidad INDELEGABLE — otorgar permisos es la llave que se
 * queda con el dueño; si se pudiera delegar, un delegado se auto-asignaría todo lo demás.
 */
class PermissionMatrixController extends Controller
{
    /**
     * GET /admin/permissions/matrix
     * Devuelve el catálogo (delegables + indelegables), los puestos del tenant y qué
     * capacidad tiene asignada cada puesto hoy.
     */
    public function getMatrix(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $delegable = collect(PermissionCatalog::DELEGABLE)
            ->map(fn ($desc, $name) => ['name' => $name, 'description' => $desc, 'delegable' => true])
            ->values();

        $indelegable = collect(PermissionCatalog::INDELEGABLE)
            ->map(fn ($desc, $name) => ['name' => $name, 'description' => $desc, 'delegable' => false])
            ->values();

        $jobRoles = DB::table('job_roles')
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        // job_role_id => [nombres de capacidad]
        $matrix = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.tenant_id', $tenantId)
            ->get(['role_permissions.job_role_id', 'permissions.name'])
            ->groupBy('job_role_id')
            ->map(fn ($rows) => $rows->pluck('name')->values());

        return response()->json([
            'success' => true,
            'capabilities' => $delegable,
            'indelegable' => $indelegable,
            'supervisor_defaults' => PermissionCatalog::SUPERVISOR_DEFAULTS,
            'job_roles' => $jobRoles,
            'matrix' => $matrix,
        ]);
    }

    /**
     * PUT /admin/permissions/matrix
     * Body: { "matrix": { "<job_role_id>": ["manage_tasks", "view_reports", ...] } }
     * Reemplaza las capacidades de cada puesto listado. Ignora nombres indelegables o
     * desconocidos. Registra cada cambio en la bitácora.
     */
    public function updateMatrix(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $validated = $request->validate([
            'matrix' => 'required|array',
        ]);

        $delegableNames = PermissionCatalog::delegableNames();

        // Puestos válidos del tenant.
        $tenantJobRoleIds = DB::table('job_roles')
            ->where('tenant_id', $tenantId)
            ->pluck('id')
            ->all();

        // name => permission_id (del tenant); crea el permiso si por alguna razón falta.
        $permIdByName = DB::table('permissions')
            ->where('tenant_id', $tenantId)
            ->whereIn('name', $delegableNames)
            ->pluck('id', 'name')
            ->all();

        $rejected = [];
        $changes = [];

        DB::transaction(function () use ($validated, $tenantId, $tenantJobRoleIds, $delegableNames, &$permIdByName, &$rejected, &$changes, $request) {
            foreach ($validated['matrix'] as $jobRoleId => $capabilities) {
                $jobRoleId = (int) $jobRoleId;

                if (!in_array($jobRoleId, $tenantJobRoleIds)) {
                    $rejected[] = "job_role {$jobRoleId} no pertenece a tu empresa";
                    continue;
                }

                $capabilities = is_array($capabilities) ? $capabilities : [];
                // Solo capacidades delegables conocidas; se descartan indelegables/desconocidas.
                $validCaps = array_values(array_intersect($capabilities, $delegableNames));
                $ignored = array_values(array_diff($capabilities, $delegableNames));
                if (!empty($ignored)) {
                    $rejected[] = "puesto {$jobRoleId}: ignoradas " . implode(',', $ignored);
                }

                // Resolver ids (crear el permiso del tenant si faltara).
                $permIds = [];
                foreach ($validCaps as $cap) {
                    if (!isset($permIdByName[$cap])) {
                        $permIdByName[$cap] = DB::table('permissions')->insertGetId([
                            'tenant_id' => $tenantId,
                            'name' => $cap,
                            'description' => PermissionCatalog::DELEGABLE[$cap] ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                    $permIds[] = $permIdByName[$cap];
                }

                // Reemplazo atómico de las capacidades de este puesto.
                DB::table('role_permissions')
                    ->where('tenant_id', $tenantId)
                    ->where('job_role_id', $jobRoleId)
                    ->delete();

                foreach ($permIds as $pid) {
                    DB::table('role_permissions')->insert([
                        'tenant_id' => $tenantId,
                        'job_role_id' => $jobRoleId,
                        'permission_id' => $pid,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $changes[$jobRoleId] = $validCaps;

                \App\Helpers\SecurityLogger::log(
                    'permissions_changed',
                    "Puesto {$jobRoleId} ahora tiene: " . (empty($validCaps) ? '(ninguna)' : implode(', ', $validCaps)),
                    $tenantId,
                    $request->user()->id
                );
            }
        });

        // §46: la config cacheada de /sync/state incluye role_permissions — invalidar.
        \App\Support\TenantConfigCache::forget($tenantId);

        return response()->json([
            'success' => true,
            'message' => 'Matriz de permisos actualizada.',
            'applied' => $changes,
            'notes' => $rejected,
        ]);
    }
}
