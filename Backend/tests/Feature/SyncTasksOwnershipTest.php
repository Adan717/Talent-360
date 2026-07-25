<?php

namespace Tests\Feature;

use App\Models\Routine;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T1 (Tareas): ownership de assignments en POST /sync/tasks.
 *
 * El sync es full-state (el cliente manda arrays completos), así que las filas
 * que un empleado no puede tocar se OMITEN (200 sin aplicar), no se rechaza
 * toda la petición. Reglas:
 *  - Un empleado solo escribe assignments cuyo dueño actual y nuevo sean él
 *    mismo o el pool (user_id null): tomar/liberar/reservar del pool sigue vivo.
 *  - Un empleado no crea assignments a nombre de otro usuario.
 *  - awaiting_validation es pegajoso vía sync: solo el endpoint de validación
 *    jerárquica puede sacarlo de ahí (cierra el bypass de reintento en modo
 *    dynamic con mt_rand).
 *  - admin/supervisor conservan el comportamiento actual dentro de su tenant.
 */
class SyncTasksOwnershipTest extends TestCase
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

    private function makeUser(string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->fresh();
    }

    private function makeTask(int $id = 500): Task
    {
        return Task::create([
            'id' => $id,
            'title' => 'Tarea T1',
            'tenant_id' => 1,
            'validation_mode' => 'auto',
            'priority' => 'normal',
        ]);
    }

    private function syncAssignments(User $actor, array $assignments)
    {
        return $this->actingAs($actor)->postJson('/api/v1/sync/tasks', [
            'assignments' => $assignments,
        ]);
    }

    public function test_empleado_no_puede_modificar_assignment_de_otro_usuario(): void
    {
        $victima = $this->makeUser();
        $atacante = $this->makeUser();
        $task = $this->makeTask();

        TaskAssignment::create([
            'id' => 'own-victima-1',
            'task_id' => $task->id,
            'user_id' => $victima->id,
            'status' => 'in_progress',
            'accumulated_mins' => 5,
            'tenant_id' => 1,
        ]);

        $response = $this->syncAssignments($atacante, [[
            'id' => 'own-victima-1',
            'task_id' => $task->id,
            'user_id' => $victima->id,
            'status' => 'omitted',
            'accumulated_mins' => 999,
        ]]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'own-victima-1',
            'status' => 'in_progress',
            'accumulated_mins' => 5,
        ]);
    }

    public function test_empleado_no_puede_robar_assignment_de_otro_usuario(): void
    {
        $victima = $this->makeUser();
        $atacante = $this->makeUser();
        $task = $this->makeTask();

        TaskAssignment::create([
            'id' => 'own-victima-2',
            'task_id' => $task->id,
            'user_id' => $victima->id,
            'status' => 'pending',
            'tenant_id' => 1,
        ]);

        $response = $this->syncAssignments($atacante, [[
            'id' => 'own-victima-2',
            'task_id' => $task->id,
            'user_id' => $atacante->id,
            'status' => 'in_progress',
        ]]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'own-victima-2',
            'user_id' => $victima->id,
            'status' => 'pending',
        ]);
    }

    public function test_empleado_no_puede_crear_assignment_para_otro_usuario(): void
    {
        $victima = $this->makeUser();
        $atacante = $this->makeUser();
        $task = $this->makeTask();

        $response = $this->syncAssignments($atacante, [[
            'id' => 'nuevo-ajeno-1',
            'task_id' => $task->id,
            'user_id' => $victima->id,
            'status' => 'pending',
        ]]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('task_assignments', ['id' => 'nuevo-ajeno-1']);
    }

    public function test_empleado_puede_tomar_y_liberar_tarea_del_pool(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask();

        TaskAssignment::create([
            'id' => 'pool-1',
            'task_id' => $task->id,
            'user_id' => null,
            'status' => 'pending',
            'tenant_id' => 1,
        ]);

        // Tomar del pool (grabTaskFromPool)
        $this->syncAssignments($empleado, [[
            'id' => 'pool-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'in_progress',
        ]])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'pool-1',
            'user_id' => $empleado->id,
            'status' => 'in_progress',
        ]);

        // Liberar de vuelta al pool (releaseTask)
        $this->syncAssignments($empleado, [[
            'id' => 'pool-1',
            'task_id' => $task->id,
            'user_id' => null,
            'status' => 'pending',
        ]])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'pool-1',
            'user_id' => null,
            'status' => 'pending',
        ]);
    }

    public function test_empleado_puede_actualizar_su_propia_assignment(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask();

        TaskAssignment::create([
            'id' => 'propia-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'in_progress',
            'accumulated_mins' => 0,
            'tenant_id' => 1,
        ]);

        $this->syncAssignments($empleado, [[
            'id' => 'propia-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'paused',
            'accumulated_mins' => 12,
        ]])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'propia-1',
            'status' => 'paused',
            'accumulated_mins' => 12,
        ]);
    }

    public function test_empleado_puede_crear_assignment_propia_y_de_pool(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask();

        $this->syncAssignments($empleado, [
            [
                'id' => 'nueva-propia-1',
                'task_id' => $task->id,
                'user_id' => $empleado->id,
                'status' => 'pending',
            ],
            [
                'id' => 'nueva-pool-1',
                'task_id' => $task->id,
                'user_id' => null,
                'status' => 'pending',
            ],
        ])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'nueva-propia-1',
            'user_id' => $empleado->id,
        ]);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'nueva-pool-1',
            'user_id' => null,
        ]);
    }

    public function test_awaiting_validation_es_pegajoso_via_sync(): void
    {
        $empleado = $this->makeUser();
        // Tarea en modo 'auto': si la pegajosidad no existiera, el sync
        // recalcularía requiresValidation=false y dejaría pasar 'completed'.
        $task = $this->makeTask();

        TaskAssignment::create([
            'id' => 'awaiting-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'awaiting_validation',
            'tenant_id' => 1,
        ]);

        // Intento de auto-completar (bypass de reintento)
        $this->syncAssignments($empleado, [[
            'id' => 'awaiting-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'completed',
        ]])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'awaiting-1',
            'status' => 'awaiting_validation',
        ]);

        // Tampoco puede regresarla a in_progress para re-rolear la validación
        $this->syncAssignments($empleado, [[
            'id' => 'awaiting-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'in_progress',
        ]])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'awaiting-1',
            'status' => 'awaiting_validation',
        ]);
    }

    public function test_empleado_no_puede_bajar_validation_mode_de_tarea_existente(): void
    {
        $empleado = $this->makeUser();
        Task::create([
            'id' => 600,
            'title' => 'Rutina de caja',
            'tenant_id' => 1,
            'validation_mode' => 'forced',
            'points' => 10,
            'priority' => 'bloqueante',
        ]);

        // Payload crafteado: intenta degradar la validación y el peso de una
        // tarea de la empresa para poder auto-completarla sin supervisor.
        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'tasks' => [[
                'id' => 600,
                'title' => 'Rutina de caja',
                'validationMode' => 'auto',
                'points' => 9999,
                'priority' => 'normal',
            ]],
        ])->assertStatus(403);

        $this->assertDatabaseHas('tasks', [
            'id' => 600,
            'validation_mode' => 'forced',
            'points' => 10,
            'priority' => 'bloqueante',
        ]);
    }

    /**
     * ENMIENDA merge F4 (conflicto de PRODUCTO, decisión pendiente del jefe): el repo anfitrión
     * aplica §31 — un empleado que mande `tasks` o `routines` por /sync/tasks recibe 403 DURO,
     * sin importar si crea o edita. La línea del Reloj era granular (crear tarea dinámica sí,
     * con points/validation_mode forzados a default; editar no), lo que sostenía el botón
     * "tarea al vuelo" del dial. Se conserva el contrato del anfitrión por ser el más
     * restrictivo; estos tests pasan a afirmar ESE contrato. Lo que ambas líneas protegían —que
     * un empleado no infle points, no se auto-valide y no toque el catálogo— queda garantizado
     * (de hecho, más).
     */
    public function test_empleado_no_puede_crear_tarea_dinamica_via_sync(): void
    {
        $empleado = $this->makeUser();

        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'tasks' => [[
                'id' => 777,
                'title' => 'Tarea on-the-fly',
                'validationMode' => 'forced',
                'priority' => 'normal',
            ]],
        ])->assertStatus(403);

        $this->assertDatabaseMissing('tasks', ['id' => 777]);
    }

    public function test_empleado_no_puede_inflar_points_ni_auto_validar_tarea_dinamica(): void
    {
        $empleado = $this->makeUser();

        // El UI real (createDynamicTask) no manda points ni validationMode → defaults
        // 10/forced. Un payload crafteado intenta subir points y poner auto para
        // auto-completar sin supervisor y farmear score.
        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'tasks' => [[
                'id' => 778,
                'title' => 'Tarea dinámica inflada',
                'validationMode' => 'auto',
                'points' => 9999,
                'priority' => 'normal',
            ]],
        ])->assertStatus(403);

        // ENMIENDA merge F4: con el 403 del anfitrión la tarea inflada ni siquiera se crea —
        // garantía MÁS fuerte que la original (que la creaba con los defaults forzados).
        $this->assertDatabaseMissing('tasks', ['id' => 778]);
    }

    public function test_empleado_no_puede_crear_ni_editar_rutinas(): void
    {
        $empleado = $this->makeUser();
        Routine::create([
            'id' => 800,
            'title' => 'Apertura original',
            'trigger' => 'apertura',
            'assign_mode' => 'equitativo',
            'tenant_id' => 1,
        ]);

        $this->actingAs($empleado)->postJson('/api/v1/sync/tasks', [
            'routines' => [
                [
                    'id' => 800,
                    'title' => 'Apertura HACKEADA',
                    'trigger' => 'apertura',
                    'assignMode' => 'equitativo',
                ],
                [
                    'id' => 801,
                    'title' => 'Rutina nueva del empleado',
                    'trigger' => 'apertura',
                    'assignMode' => 'equitativo',
                ],
            ],
        ])->assertStatus(403);

        // ENMIENDA merge F4: el anfitrión rechaza el lote entero (403) en vez de ignorar las
        // filas vedadas. El invariante es el mismo: la existente no se altera y la nueva no nace.
        $this->assertDatabaseHas('routines', ['id' => 800, 'title' => 'Apertura original']);
        $this->assertDatabaseMissing('routines', ['id' => 801]);
    }

    public function test_empleado_no_puede_completar_tarea_de_pool_sin_dueno(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask();

        // Intento de crear una completación fantasma en el pool (user_id null)
        // para saltarse la validación jerárquica (que keyea sobre el usuario).
        $this->syncAssignments($empleado, [[
            'id' => 'pool-fantasma-1',
            'task_id' => $task->id,
            'user_id' => null,
            'status' => 'completed',
        ]])->assertStatus(200);

        $this->assertDatabaseMissing('task_assignments', [
            'id' => 'pool-fantasma-1',
            'status' => 'completed',
        ]);
    }

    public function test_admin_si_puede_editar_tarea_y_rutina(): void
    {
        $admin = $this->makeUser('admin');
        Task::create([
            'id' => 900,
            'title' => 'Tarea admin',
            'tenant_id' => 1,
            'validation_mode' => 'forced',
            'priority' => 'normal',
        ]);
        Routine::create([
            'id' => 901,
            'title' => 'Rutina admin',
            'trigger' => 'apertura',
            'assign_mode' => 'equitativo',
            'tenant_id' => 1,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/sync/tasks', [
            'tasks' => [[
                'id' => 900,
                'title' => 'Tarea admin v2',
                'validationMode' => 'auto',
                'priority' => 'normal',
            ]],
            'routines' => [[
                'id' => 901,
                'title' => 'Rutina admin v2',
                'trigger' => 'apertura',
                'assignMode' => 'checklist',
            ]],
        ])->assertStatus(200);

        $this->assertDatabaseHas('tasks', ['id' => 900, 'validation_mode' => 'auto']);
        $this->assertDatabaseHas('routines', ['id' => 901, 'title' => 'Rutina admin v2']);
    }

    public function test_admin_puede_modificar_y_reasignar_assignments_ajenas(): void
    {
        $admin = $this->makeUser('admin');
        $empleadoA = $this->makeUser();
        $empleadoB = $this->makeUser();
        $task = $this->makeTask();

        TaskAssignment::create([
            'id' => 'admin-mueve-1',
            'task_id' => $task->id,
            'user_id' => $empleadoA->id,
            'status' => 'pending',
            'tenant_id' => 1,
        ]);

        $this->syncAssignments($admin, [[
            'id' => 'admin-mueve-1',
            'task_id' => $task->id,
            'user_id' => $empleadoB->id,
            'status' => 'in_progress',
        ]])->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'admin-mueve-1',
            'user_id' => $empleadoB->id,
            'status' => 'in_progress',
        ]);
    }
}
