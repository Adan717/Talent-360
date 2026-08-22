<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tomar una tarea de la Bolsa de Trabajo le pone dueño (2026-08-22, prueba en vivo).
 *
 * Una tarea libre vive con user_id NULL. Al pulsar "Iniciar Ya" el navegador manda sólo el
 * status y el endpoint nunca aceptó user_id: la tarea quedaba EN CURSO SIN DUEÑO — no salía en
 * "Mis Tareas", las monedas y los puntos al validarla no eran de nadie, y dos personas podían
 * tomar la misma.
 */
class TomarTareaDeLaBolsaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Bolsa QA', 'subdomain' => 'bolsaqa', 'plan' => 'enterprise', 'is_active' => true]);
    }

    private function persona(string $nombre): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '.', $nombre)) . '@bolsaqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);
        // Con turno abierto: sin check_in el endpoint rechaza trabajar una tarea.
        app(\App\Services\ClockService::class)->processPunch($user, 'check_in');

        return $user;
    }

    private function tareaLibre(): string
    {
        $taskId = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->tenant->id, 'title' => 'Conciliación bancaria', 'estimated_mins' => 20,
            'priority' => 'alta', 'category' => 'operativo', 'target_type' => 'pool',
            'points' => 10, 'validation_mode' => 'auto',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = 'bolsa_' . $taskId;
        DB::table('task_assignments')->insert([
            'id' => $id, 'task_id' => $taskId, 'user_id' => null, 'tenant_id' => $this->tenant->id,
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for($this->tenant->id))->toDateString(),
            'status' => 'pending', 'points_awarded' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_al_iniciarla_la_tarea_queda_a_nombre_de_quien_la_tomo(): void
    {
        $miguel = $this->persona('Miguel Emp');
        $id = $this->tareaLibre();

        $this->actingAs($miguel)->putJson("/api/v1/task-assignments/{$id}", ['status' => 'in_progress'])
            ->assertOk();

        $this->assertDatabaseHas('task_assignments', [
            'id' => $id, 'user_id' => $miguel->id, 'status' => 'in_progress',
        ]);
    }

    public function test_si_otro_se_adelanto_no_se_le_pisa(): void
    {
        $miguel = $this->persona('Miguel Emp');
        $ana = $this->persona('Ana Emp');
        $id = $this->tareaLibre();

        $this->actingAs($miguel)->putJson("/api/v1/task-assignments/{$id}", ['status' => 'in_progress'])->assertOk();

        // Ana llega tarde a la misma tarea: ya tiene dueño, así que le corresponde el portazo de
        // ownership del endpoint (no es suya), no quedarse con el trabajo de Miguel.
        $this->actingAs($ana)->putJson("/api/v1/task-assignments/{$id}", ['status' => 'completed'])
            ->assertStatus(403);

        $this->assertDatabaseHas('task_assignments', ['id' => $id, 'user_id' => $miguel->id]);
    }
}
