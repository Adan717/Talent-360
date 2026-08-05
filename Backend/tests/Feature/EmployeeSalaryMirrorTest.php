<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * H1 (prueba en vivo 2026-07-29): el alta de colaborador manda el sueldo en `salary`
 * (RecursosHumanos.tsx) y `EmployeeController` lo persistía tal cual, dejando `base_salary`
 * en NULL. Pero TODO el cálculo de dinero lee `base_salary`:
 *   - costo financiero de cada tarea: `base_salary > 0 ? base_salary : 300.00`
 *     (TaskAssignmentController x3 y TaskSyncController)
 *   - snapshot inmutable del ponche: `base_salary_at_time`
 * Resultado: cualquier colaborador dado de alta desde la pantalla se calculaba con el
 * salario por defecto de $300, sin importar lo capturado.
 *
 * Regla de esta ronda: `base_salary` es la COLUMNA DE VERDAD y se mantiene espejada con
 * `salary` en el alta y en la edición, venga la que venga.
 */
class EmployeeSalaryMirrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'DecorArte', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->fresh();
    }

    public function test_alta_con_salary_puebla_base_salary(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/v1/employees', [
            'name' => 'Francisco Vega',
            'email' => 'francisco@decorarte.test',
            'role' => 'empleado',
            'hire_date' => '2026-08-01',
            'salary' => 14000,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('employees', [
            'email' => 'francisco@decorarte.test',
            'salary' => 14000,
            'base_salary' => 14000,
        ]);
    }

    public function test_alta_con_base_salary_puebla_salary(): void
    {
        $res = $this->actingAs($this->admin())->postJson('/api/v1/employees', [
            'name' => 'Marisol Herrera',
            'email' => 'marisol@decorarte.test',
            'role' => 'empleado',
            'hire_date' => '2026-08-01',
            'base_salary' => 18000,
        ]);

        $res->assertStatus(201);
        $this->assertDatabaseHas('employees', [
            'email' => 'marisol@decorarte.test',
            'salary' => 18000,
            'base_salary' => 18000,
        ]);
    }

    public function test_editar_el_sueldo_actualiza_ambas_columnas(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/v1/employees', [
            'name' => 'Adán Cuéllar',
            'email' => 'adan@decorarte.test',
            'role' => 'empleado',
            'hire_date' => '2026-08-01',
            'salary' => 9000,
        ])->assertStatus(201);

        $id = DB::table('employees')->where('email', 'adan@decorarte.test')->value('id');

        $this->actingAs($admin)->putJson("/api/v1/employees/{$id}", ['salary' => 11000])
            ->assertStatus(200);

        $this->assertDatabaseHas('employees', [
            'id' => $id, 'salary' => 11000, 'base_salary' => 11000,
        ]);
    }

    /**
     * H4: el alta debe respetar el nivel de acceso elegido. El formulario mandaba siempre
     * 'empleado' y había que editar la ficha después para nombrar a un supervisor o admin;
     * ya expone el selector, así que se fija que el backend lo honre.
     */
    public function test_el_alta_respeta_el_rol_de_sistema_elegido(): void
    {
        $admin = $this->admin();

        foreach (['supervisor', 'admin', 'empleado'] as $rol) {
            $this->actingAs($admin)->postJson('/api/v1/employees', [
                'name' => "Persona {$rol}",
                'email' => "{$rol}@decorarte.test",
                'role' => $rol,
                'hire_date' => '2026-08-01',
            ])->assertStatus(201);

            $this->assertDatabaseHas('users', ['email' => "{$rol}@decorarte.test", 'role' => $rol]);
        }
    }

    /**
     * El punto de la historia: que el sueldo capturado se USE en el dinero. Antes, con
     * `base_salary` nulo, el costo caía al default de $300/día.
     */
    public function test_el_costo_de_la_tarea_usa_el_sueldo_capturado_y_no_el_default(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/api/v1/employees', [
            'name' => 'Costoso Pérez',
            'email' => 'costoso@decorarte.test',
            'role' => 'empleado',
            'hire_date' => '2026-08-01',
            'salary' => 4800, // 4800/480 = 10.00 por minuto
        ])->assertStatus(201);

        $userId = DB::table('employees')->where('email', 'costoso@decorarte.test')->value('user_id');
        $task = Task::create(['id' => 900, 'title' => 'Tarea cara', 'tenant_id' => 1, 'points' => 10, 'validation_mode' => 'auto']);
        TaskAssignment::create([
            'id' => 'costo-1', 'task_id' => $task->id, 'user_id' => $userId,
            'status' => 'in_progress', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $this->actingAs($admin)->putJson('/api/v1/task-assignments/costo-1', [
            'status' => 'completed',
            'accumulated_mins' => 30,
        ])->assertStatus(200);

        // (4800 / 480) * 30 = 300.00 con el sueldo real. Con el default de 300 habrían
        // sido (300/480)*30 = 18.75.
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'costo-1',
            'task_cost' => 300.00,
        ]);
    }
}
