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
 * Colaborador SIN PUESTO: se avisa, no se bloquea (criterio del dueño).
 *
 * Un expediente sin `job_role_id` no puede fichar por kiosko (R53), no aparece en el
 * organigrama y no se le puede dar ninguna capacidad — pero RRHH lo pintaba igual que a
 * cualquiera, e incluso el selector de puesto le mostraba el PRIMER puesto de la lista aunque
 * no tuviera ninguno: la pantalla afirmaba un puesto que la base no tenía.
 *
 * El aviso vive en el frontend (banda ámbar en el directorio + insignia en la tarjeta +
 * opción "— Sin puesto asignado —" en la ficha). Lo que se prueba aquí es lo que el servidor
 * tiene que sostener para que ese aviso sea honesto:
 *
 *  - el listado dice quién no tiene puesto (`job_role_id` null, no ausente);
 *  - elegir "— Sin puesto asignado —" y guardar QUITA el puesto de verdad;
 *  - y el fichaje sigue SIN bloquearse por ello (avisar no es impedir).
 */
class SinPuestoAvisaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private JobRole $puesto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Sin Puesto QA', 'subdomain' => 'sinpuestoqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
            'esAperturador' => false, 'tiempoTolerancia' => 10,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@sinpuestoqa.test',
            'password' => bcrypt('x'), 'role' => 'admin', 'is_active' => true,
        ]);
    }

    private function fichaSinPuesto(): Employee
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Nadie Puesto',
            'email' => 'nadie@sinpuestoqa.test', 'password' => bcrypt('x'),
            'role' => 'empleado', 'is_active' => true,
        ]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Nadie Puesto', 'email' => 'nadie@sinpuestoqa.test',
            'job_role_id' => null, 'is_active_employee' => true,
            'salary' => 3000, 'base_salary' => 3000,
        ]);
    }

    public function test_el_listado_dice_quien_no_tiene_puesto(): void
    {
        $sinPuesto = $this->fichaSinPuesto();

        $lista = $this->actingAs($this->admin)->getJson('/api/v1/employees')
            ->assertStatus(200)
            ->json();

        $lista = $lista['data'] ?? $lista;
        $fila = collect($lista)->firstWhere('id', $sinPuesto->id);

        $this->assertNotNull($fila, 'el que no tiene puesto sigue apareciendo en el directorio: se avisa, no se esconde');
        $this->assertArrayHasKey('job_role_id', $fila, 'sin este campo el aviso no se puede calcular');
        $this->assertNull($fila['job_role_id']);
    }

    public function test_quitar_el_puesto_desde_la_ficha_se_guarda_de_verdad(): void
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Con Puesto',
            'email' => 'conpuesto@sinpuestoqa.test', 'password' => bcrypt('x'),
            'role' => 'empleado', 'is_active' => true,
        ]);
        $ficha = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Con Puesto', 'email' => 'conpuesto@sinpuestoqa.test',
            'job_role_id' => $this->puesto->id, 'is_active_employee' => true,
            'salary' => 3000, 'base_salary' => 3000,
        ]);

        $this->actingAs($this->admin)
            ->putJson("/api/v1/employees/{$ficha->id}", [
                'name' => 'Con Puesto', 'email' => 'conpuesto@sinpuestoqa.test',
                'role' => 'empleado', 'job_role_id' => null,
            ])
            ->assertStatus(200);

        $this->assertNull(DB::table('employees')->where('id', $ficha->id)->value('job_role_id'),
            'la ficha ofrece "— Sin puesto asignado —": si el servidor lo ignorara, la pantalla mentiría otra vez');
        $this->assertNull(DB::table('users')->where('id', $user->id)->value('job_role_id'),
            'las dos tablas guardan el puesto y las dos tienen que quedar iguales');
    }

    public function test_sin_puesto_se_avisa_pero_el_fichaje_no_se_bloquea(): void
    {
        $sinPuesto = $this->fichaSinPuesto();

        $respuesta = $this->actingAs($sinPuesto->user)->postJson('/api/v1/clock/punch', [
            'user_id' => $sinPuesto->user_id,
            'type' => 'check_in',
        ]);

        $this->assertNotEquals(403, $respuesta->status(),
            'criterio del dueño: nada bloquea, todo avisa — el aviso está en RRHH, no en la puerta del reloj');
        $this->assertDatabaseHas('time_entries', ['user_id' => $sinPuesto->user_id]);
    }
}
