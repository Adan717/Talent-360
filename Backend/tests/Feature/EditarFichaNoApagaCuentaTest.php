<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guardar la ficha de un colaborador NO puede apagarle la cuenta (2026-08-08).
 *
 * `EmployeeController::update` hacía, para cualquier guardado con rol 'empleado':
 *
 *     if ($request->role === 'empleado') { $userUpdates['is_active'] = false; }
 *
 * y la línea siguiente impedía además que el `is_active` del propio formulario lo volviera
 * a encender. Como RRHH manda el expediente COMPLETO en cada guardado (incluido `role`),
 * bastaba corregirle el teléfono a alguien —o arrastrar su tarjeta en el organigrama— para
 * que dejara de poder entrar: el login responde 403 "Usuario inactivo / archivado".
 *
 * La línea la introdujo un commit de julio sobre otro tema ("ventana de acceso previo"),
 * no una decisión de producto: el colaborador ENTRA a la aplicación, el reloj es suyo.
 */
class EditarFichaNoApagaCuentaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $colaborador;
    private Employee $ficha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'RRHH QA', 'subdomain' => 'rrhhqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@rrhhqa.test',
            'password' => bcrypt('secreto123'), 'role' => 'admin', 'is_active' => true,
        ]);

        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Ana Cajera', 'email' => 'ana@rrhhqa.test',
            'password' => bcrypt('secreto123'), 'role' => 'empleado', 'is_active' => true,
        ]);

        $this->ficha = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'name' => 'Ana Cajera', 'email' => 'ana@rrhhqa.test', 'job_role_id' => $puesto->id,
            'is_active_employee' => true, 'base_salary' => 2400,
        ]);
    }

    /** El guardado que manda RRHH: el expediente completo, con `role`. */
    private function guardarFicha(array $cambios = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)->putJson("/api/v1/employees/{$this->ficha->id}", array_merge([
            'name' => 'Ana Cajera',
            'email' => 'ana@rrhhqa.test',
            'role' => 'empleado',
            'job_role_id' => $this->ficha->job_role_id,
            'is_active' => true,
            'base_salary' => 2400,
        ], $cambios));
    }

    public function test_corregir_el_telefono_no_le_quita_el_acceso(): void
    {
        $this->guardarFicha(['phone' => '5512345678'])->assertOk();

        $this->assertTrue(
            (bool) DB::table('users')->where('id', $this->colaborador->id)->value('is_active'),
            'guardar la ficha no puede apagar la cuenta de quien trabaja aquí'
        );
    }

    /** La prueba de verdad: después de guardar, ¿puede entrar? */
    public function test_despues_de_guardar_la_ficha_sigue_pudiendo_entrar(): void
    {
        $this->guardarFicha(['phone' => '5512345678'])->assertOk();

        $this->postJson('/api/v1/login', [
            'email' => 'ana@rrhhqa.test',
            'password' => 'secreto123',
        ])->assertOk();
    }

    /** Reasignar el puesto arrastrando en el organigrama manda el mismo payload completo. */
    public function test_reasignar_el_puesto_tampoco_le_quita_el_acceso(): void
    {
        $otroPuesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Almacén', 'area' => 'Bodega',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $this->guardarFicha(['job_role_id' => $otroPuesto->id])->assertOk();

        $this->assertTrue(
            (bool) DB::table('users')->where('id', $this->colaborador->id)->value('is_active')
        );
    }

    /** Y dar de baja de verdad SÍ tiene que apagarla (no romper el candado que sí importa). */
    public function test_dar_de_baja_si_apaga_la_cuenta(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/employees/{$this->ficha->id}")
            ->assertOk();

        $this->assertFalse(
            (bool) DB::table('users')->where('id', $this->colaborador->id)->value('is_active'),
            'la baja real sigue cerrando el acceso'
        );
    }

    /** Si el admin apaga la cuenta a propósito desde el formulario, se respeta. */
    public function test_el_admin_puede_desactivar_a_proposito(): void
    {
        $this->guardarFicha(['is_active' => false])->assertOk();

        $this->assertFalse(
            (bool) DB::table('users')->where('id', $this->colaborador->id)->value('is_active'),
            'antes este caso era imposible: el is_active del formulario se ignoraba para "empleado"'
        );
    }
}
