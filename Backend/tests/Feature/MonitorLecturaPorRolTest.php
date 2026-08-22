<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La LECTURA del Monitor 360 es del rol, no del puesto (2026-08-22, prueba en vivo).
 *
 * Una supervisora recién dada de alta —puesto sin permisos delegados— recibía 403 en
 * GET /admin/dashboard/monitor; la pantalla se lo tragaba y mostraba "0 / 0, no hay
 * colaboradores activos" mientras el admin estaba en turno. El Monitor es su pantalla de
 * inicio. Las acciones (asignar tareas, Kill-Switch, chat) siguen exigiendo permiso de puesto.
 */
class MonitorLecturaPorRolTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Monitor QA', 'subdomain' => 'monitorqa', 'plan' => 'enterprise', 'is_active' => true]);
    }

    private function persona(string $rol, string $nombre): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)) . '@monitorqa.test',
            'password' => bcrypt('x'), 'role' => $rol,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);

        return $user;
    }

    public function test_una_supervisora_sin_permisos_de_puesto_lee_el_monitor(): void
    {
        $supervisora = $this->persona('supervisor', 'Maria Sup');

        $this->actingAs($supervisora)->getJson('/api/v1/admin/dashboard/monitor')
            ->assertOk()
            ->assertJsonPath('status', 'success');
    }

    /**
     * Las ACCIONES del monitor siguen tras el permiso; lo que cambió (2026-08-22) es que el rol de
     * supervisor ya trae SUPERVISOR_DEFAULTS de fábrica, así que la supervisora las alcanza: aquí
     * el 409 es "no hay turno abierto que cerrar", no un portazo. Quien no es mando sigue fuera.
     */
    public function test_un_empleado_no_ejecuta_las_acciones_del_monitor(): void
    {
        $empleado = $this->persona('empleado', 'Miguel Emp');

        $this->actingAs($empleado)->postJson('/api/v1/admin/dashboard/force-close-shift', ['user_id' => $empleado->id])
            ->assertStatus(403);
    }

    public function test_la_supervisora_alcanza_las_acciones_del_monitor(): void
    {
        $supervisora = $this->persona('supervisor', 'Maria Sup');

        $res = $this->actingAs($supervisora)->postJson('/api/v1/admin/dashboard/force-close-shift', ['user_id' => $supervisora->id]);

        $this->assertNotSame(403, $res->status(), 'una supervisora no debe recibir un portazo de permisos en su propio monitor');
    }

    public function test_un_empleado_sigue_sin_ver_el_monitor(): void
    {
        $empleado = $this->persona('empleado', 'Miguel Emp');

        $this->actingAs($empleado)->getJson('/api/v1/admin/dashboard/monitor')->assertStatus(403);
    }
}
