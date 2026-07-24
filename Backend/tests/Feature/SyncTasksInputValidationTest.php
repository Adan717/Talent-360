<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T4 (Tareas): validación de input en POST /sync/tasks.
 *
 * El endpoint solo validaba tasks.*.id/title; los assignments entraban crudos
 * (status sin enum → rompía las comparaciones de pegajosidad; mins sin integer;
 * campos requeridos ausentes → 500). Se alinea con lo que ya valida
 * TaskAssignmentController::update, SIN romper el flujo donde el mismo payload
 * crea una tarea nueva y la referencia en un assignment (createDynamicTask):
 * por eso task_id NO lleva `exists`.
 */
class SyncTasksInputValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'DecorArte 360',
            'subdomain' => 'decorarte360',
            'public_slug' => 'decorarte360',
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->fresh();
    }

    public function test_status_invalido_se_omite_sin_tumbar_el_lote(): void
    {
        // El sync es full-state; una fila con status fuera del enum se OMITE (no
        // rechaza el lote entero, que sí incluiría filas válidas hidratadas del DB).
        $empleado = $this->makeUser();
        $task = Task::create(['id' => 400, 'title' => 'T', 'tenant_id' => 1]);

        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'assignments' => [
                [
                    'id' => 'bad-status-1',
                    'task_id' => $task->id,
                    'user_id' => $empleado->id,
                    'status' => 'lol-no-existe',
                ],
                [
                    'id' => 'good-status-1',
                    'task_id' => $task->id,
                    'user_id' => $empleado->id,
                    'status' => 'in_progress',
                ],
            ],
        ])->assertStatus(200);

        // La basura se descarta, la buena persiste.
        $this->assertDatabaseMissing('task_assignments', ['id' => 'bad-status-1']);
        $this->assertDatabaseHas('task_assignments', ['id' => 'good-status-1', 'status' => 'in_progress']);
    }

    public function test_assignment_sin_status_se_omite(): void
    {
        $empleado = $this->makeUser();
        $task = Task::create(['id' => 406, 'title' => 'T', 'tenant_id' => 1]);

        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'assignments' => [[
                'id' => 'no-status-1',
                'task_id' => $task->id,
                'user_id' => $empleado->id,
            ]],
        ])->assertStatus(200);

        $this->assertDatabaseMissing('task_assignments', ['id' => 'no-status-1']);
    }

    public function test_mins_no_entero_es_rechazado(): void
    {
        $empleado = $this->makeUser();
        $task = Task::create(['id' => 401, 'title' => 'T', 'tenant_id' => 1]);

        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'assignments' => [[
                'id' => 'bad-mins-1',
                'task_id' => $task->id,
                'user_id' => $empleado->id,
                'status' => 'in_progress',
                'accumulatedMins' => 'muchos',
            ]],
        ])->assertStatus(422);
    }

    public function test_assignment_sin_id_es_rechazado(): void
    {
        $empleado = $this->makeUser();
        $task = Task::create(['id' => 402, 'title' => 'T', 'tenant_id' => 1]);

        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'assignments' => [[
                'task_id' => $task->id,
                'user_id' => $empleado->id,
                'status' => 'in_progress',
            ]],
        ])->assertStatus(422);
    }

    public function test_payload_valido_pasa(): void
    {
        $empleado = $this->makeUser();
        $task = Task::create(['id' => 403, 'title' => 'T', 'tenant_id' => 1]);

        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'assignments' => [[
                'id' => 'ok-1',
                'task_id' => $task->id,
                'user_id' => $empleado->id,
                'status' => 'in_progress',
                'accumulatedMins' => 5,
            ]],
        ])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', ['id' => 'ok-1', 'status' => 'in_progress']);
    }

    public function test_crear_tarea_y_asignarla_en_el_mismo_payload_no_rompe(): void
    {
        // Regresión: createDynamicTask crea la Task y su assignment en un solo sync.
        // task_id NO debe llevar `exists` o esto fallaría (la tarea aún no existe).
        $empleado = $this->makeUser();

        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'tasks' => [[
                'id' => 4040,
                'title' => 'Tarea on-the-fly',
            ]],
            'assignments' => [[
                'id' => 'dyn-4040',
                'task_id' => 4040,
                'user_id' => null,
                'status' => 'pending',
            ]],
        ])->assertStatus(200);

        $this->assertDatabaseHas('tasks', ['id' => 4040]);
        $this->assertDatabaseHas('task_assignments', ['id' => 'dyn-4040', 'status' => 'pending']);
    }

    public function test_assignment_con_task_id_inexistente_se_omite_sin_tumbar_el_lote(): void
    {
        // Regresión (Ley Silla): la tarea "sentado" por defecto usa un id virtual (9999)
        // que no existe en `tasks`. Al sincronizar su assignment, el FK
        // task_assignments_task_id_foreign reventaba con 500 y tumbaba TODO el batch
        // full-state (incluidas filas válidas). Ahora la fila con task_id fantasma se OMITE
        // y el resto persiste (misma filosofía skip-no-rechazo que el status inválido).
        $empleado = $this->makeUser();
        $task = Task::create(['id' => 407, 'title' => 'T', 'tenant_id' => 1]);

        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'assignments' => [
                [
                    'id' => 'seat_ghost',
                    'task_id' => 9999, // no existe en `tasks`
                    'user_id' => $empleado->id,
                    'status' => 'in_progress',
                ],
                [
                    'id' => 'real-1',
                    'task_id' => $task->id,
                    'user_id' => $empleado->id,
                    'status' => 'in_progress',
                ],
            ],
        ])->assertStatus(200);

        $this->assertDatabaseMissing('task_assignments', ['id' => 'seat_ghost']);
        $this->assertDatabaseHas('task_assignments', ['id' => 'real-1', 'status' => 'in_progress']);
    }

    public function test_routine_sin_trigger_es_rechazada(): void
    {
        $empleado = $this->makeUser();

        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'routines' => [[
                'id' => 405,
                'title' => 'Rutina sin trigger',
            ]],
        ])->assertStatus(422);
    }
}
