<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El rol de supervisor trae sus capacidades de fábrica (2026-08-22, prueba en vivo).
 *
 * La migración 2026_07_26_000001 repartió SUPERVISOR_DEFAULTS a los puestos de las empresas que
 * existían ESE día. A las empresas nuevas no se los da nadie: la empresa de la prueba tenía 11
 * puestos y CERO filas en role_permissions. Y como la matriz de permisos
 * (GET/PUT /admin/permissions/matrix) no la consume ninguna pantalla, no había forma de
 * otorgarlos. Resultado: la supervisora recibía 403 al validar una tarea o al ver el monitor —
 * su rol era decorativo. Ahora el rol trae el set conservador; el puesto sigue sirviendo para
 * capacidades adicionales o para puestos que no son de mando.
 */
class SupervisorTraeSusPermisosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Permisos QA', 'subdomain' => 'permisosqa', 'plan' => 'enterprise', 'is_active' => true]);
    }

    /** Una persona con puesto, SIN una sola fila en role_permissions (empresa recién creada). */
    private function persona(string $rol, string $nombre): User
    {
        $puesto = JobRole::create(['tenant_id' => $this->tenant->id, 'name' => $nombre . ' Puesto', 'area' => 'Operaciones']);
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)) . '@permisosqa.test',
            'password' => bcrypt('x'), 'role' => $rol, 'job_role_id' => $puesto->id,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'job_role_id' => $puesto->id, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);

        return $user;
    }

    public function test_una_empresa_nueva_no_tiene_permisos_por_puesto(): void
    {
        $this->persona('supervisor', 'Maria Sup');

        $this->assertSame(0, DB::table('role_permissions')->where('tenant_id', $this->tenant->id)->count(),
            'la premisa de esta prueba es una empresa sin permisos delegados por puesto');
    }

    public function test_el_supervisor_valida_tareas_sin_permisos_por_puesto(): void
    {
        $supervisora = $this->persona('supervisor', 'Maria Sup');

        // approve_operations ("...validar tareas") es parte del set por defecto.
        $this->assertContains('approve_operations', PermissionCatalog::SUPERVISOR_DEFAULTS);
        $this->assertTrue(
            \App\Http\Middleware\PermissionMiddleware::usuarioTiene($supervisora, 'approve_operations'),
            'una supervisora debe poder aprobar operaciones sin depender de la matriz de puestos'
        );
    }

    public function test_pero_no_alcanza_lo_que_no_es_suyo(): void
    {
        $supervisora = $this->persona('supervisor', 'Maria Sup');

        // Nómina y salarios NO están en el set conservador (raíz del §64).
        $this->assertFalse(
            \App\Http\Middleware\PermissionMiddleware::usuarioTiene($supervisora, 'manage_payroll'),
            'la nómina no viaja con el rol de supervisor'
        );
        $this->assertFalse(
            \App\Http\Middleware\PermissionMiddleware::usuarioTiene($supervisora, 'view_salaries'),
            'los salarios no viajan con el rol de supervisor'
        );
    }

    public function test_un_empleado_no_hereda_nada(): void
    {
        $empleado = $this->persona('empleado', 'Miguel Emp');

        foreach (PermissionCatalog::SUPERVISOR_DEFAULTS as $capacidad) {
            $this->assertFalse(
                \App\Http\Middleware\PermissionMiddleware::usuarioTiene($empleado, $capacidad),
                "un colaborador no debe heredar {$capacidad}"
            );
        }
    }

    /**
     * El puesto CONFIGURADO manda: con una sola capacidad concedida se acabaron los defaults.
     * Un puesto de mando con sólo `view_reports` (lectura) no cierra turnos — hallazgo de la
     * ronda del Monitor que esta regla no puede deshacer.
     */
    public function test_un_puesto_configurado_manda_sobre_los_defaults(): void
    {
        $supervisora = $this->persona('supervisor', 'Ana Analista');
        $jobRoleId = DB::table('employees')->where('user_id', $supervisora->id)->value('job_role_id');
        $permId = DB::table('permissions')
            ->where('tenant_id', $this->tenant->id)->where('name', 'view_reports')->value('id');
        DB::table('role_permissions')->insert([
            'tenant_id' => $this->tenant->id, 'job_role_id' => $jobRoleId,
            'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(\App\Http\Middleware\PermissionMiddleware::usuarioTiene($supervisora, 'view_reports'));
        $this->assertFalse(
            \App\Http\Middleware\PermissionMiddleware::usuarioTiene($supervisora, 'manage_store_opening'),
            'una capacidad de lectura no puede convertirse en poder de escritura'
        );
        $this->assertFalse(
            \App\Http\Middleware\PermissionMiddleware::usuarioTiene($supervisora, 'approve_operations'),
            'con el puesto ya configurado, los defaults no se suman'
        );
    }

    public function test_el_puesto_sigue_otorgando_capacidades_a_quien_no_es_mando(): void
    {
        $empleado = $this->persona('empleado', 'Miguel Emp');
        $jobRoleId = DB::table('employees')->where('user_id', $empleado->id)->value('job_role_id');

        // El catálogo ya lo sembró TenantInitializationService al crear la empresa.
        $permId = DB::table('permissions')
            ->where('tenant_id', $this->tenant->id)->where('name', 'manage_tasks')->value('id');
        $this->assertNotNull($permId, 'el catálogo de capacidades debe nacer con la empresa');
        DB::table('role_permissions')->insert([
            'tenant_id' => $this->tenant->id, 'job_role_id' => $jobRoleId,
            'permission_id' => $permId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue(\App\Http\Middleware\PermissionMiddleware::usuarioTiene($empleado, 'manage_tasks'));
    }
}
