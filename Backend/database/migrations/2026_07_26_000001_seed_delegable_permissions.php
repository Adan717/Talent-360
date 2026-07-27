<?php

use App\Support\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §65: siembra el catálogo de capacidades delegables por puesto y asigna los defaults.
 *
 * 1. Relaja el UNIQUE global de permissions.name a UNIQUE(tenant_id, name) — igual que se
 *    hizo con system_settings: los permisos son por-tenant, así que el nombre debe ser
 *    único por empresa, no globalmente (si no, la 2ª empresa no puede tener 'manage_tasks').
 * 2. Crea las 12 capacidades delegables para cada tenant existente.
 * 3. Asigna defaults conservadores: puestos de admin = todas; puestos de supervisor =
 *    {manage_tasks, approve_operations, manage_store_opening, view_reports}.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. UNIQUE(name) global -> UNIQUE(tenant_id, name).
        // ->unique() de Laravel crea un índice único aparte (permissions_name_unique),
        // así que dropUnique(['name']) funciona igual en pgsql y sqlite.
        Schema::table('permissions', function (Blueprint $table) {
            try {
                $table->dropUnique(['name']);
            } catch (\Throwable $e) {
                // Ya estaba relajado en un entorno previo; no es fatal.
            }
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->unique(['tenant_id', 'name'], 'permissions_tenant_name_unique');
        });

        // 2 y 3. Sembrar catálogo + defaults por tenant.
        $tenantIds = DB::table('tenants')->pluck('id');

        foreach ($tenantIds as $tenantId) {
            try {
                $this->seedTenant((int) $tenantId);
            } catch (\Throwable $e) {
                \Log::error("§65: error sembrando permisos para tenant {$tenantId}: " . $e->getMessage());
            }
        }
    }

    private function seedTenant(int $tenantId): void
    {
        // Crea (si faltan) las 12 capacidades del tenant y devuelve name => id.
        $permId = [];
        foreach (PermissionCatalog::DELEGABLE as $name => $description) {
            $existing = DB::table('permissions')
                ->where('tenant_id', $tenantId)
                ->where('name', $name)
                ->value('id');

            if ($existing) {
                $permId[$name] = $existing;
                continue;
            }

            $permId[$name] = DB::table('permissions')->insertGetId([
                'tenant_id'   => $tenantId,
                'name'        => $name,
                'description' => $description,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        // Puestos por nivel: se derivan de users.role de quienes ocupan cada puesto.
        $adminJobRoleIds = $this->jobRoleIdsForRole($tenantId, 'admin');
        $supervisorJobRoleIds = $this->jobRoleIdsForRole($tenantId, 'supervisor');

        // Admin: todas las capacidades (aunque el middleware ya los deja pasar por bypass,
        // dejar la fila hace consistente la matriz que ve el administrador).
        foreach ($adminJobRoleIds as $jobRoleId) {
            $this->grant($tenantId, $jobRoleId, array_values($permId));
        }

        // Supervisor: set conservador.
        $supervisorPermIds = array_values(array_intersect_key(
            $permId,
            array_flip(PermissionCatalog::SUPERVISOR_DEFAULTS)
        ));
        foreach ($supervisorJobRoleIds as $jobRoleId) {
            $this->grant($tenantId, $jobRoleId, $supervisorPermIds);
        }
    }

    private function jobRoleIdsForRole(int $tenantId, string $role): array
    {
        return DB::table('employees')
            ->join('users', 'users.id', '=', 'employees.user_id')
            ->where('employees.tenant_id', $tenantId)
            ->where('users.role', $role)
            ->whereNotNull('employees.job_role_id')
            ->distinct()
            ->pluck('employees.job_role_id')
            ->all();
    }

    private function grant(int $tenantId, int $jobRoleId, array $permissionIds): void
    {
        foreach ($permissionIds as $permissionId) {
            $exists = DB::table('role_permissions')
                ->where('tenant_id', $tenantId)
                ->where('job_role_id', $jobRoleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (!$exists) {
                DB::table('role_permissions')->insert([
                    'tenant_id'     => $tenantId,
                    'job_role_id'   => $jobRoleId,
                    'permission_id' => $permissionId,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            try {
                $table->dropUnique('permissions_tenant_name_unique');
            } catch (\Throwable $e) {
            }
        });
        Schema::table('permissions', function (Blueprint $table) {
            $table->unique('name');
        });
    }
};
