<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskSyncAssignmentGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'free',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEmployee(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    private function seedTaskAndAssignment(User $user, int $assignmentId, int $taskId): void
    {
        DB::table('tasks')->insertOrIgnore([
            'id' => $taskId,
            'tenant_id' => 1,
            'title' => 'Limpieza fina de mostrador',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('task_assignments')->insert([
            'id' => $assignmentId,
            'tenant_id' => 1,
            'task_id' => $taskId,
            'user_id' => $user->id,
            'status' => 'pending',
            // H25: la fecha va en la zona del TENANT, como la escribe producción. Con `now()`
            // —UTC— este test sólo pasaba fuera de la franja 00:00–06:00 UTC, que es cuando el
            // día del servidor y el del tenant dejan de coincidir.
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * §62: reenviar una asignación existente con user_id vacío (cliente con el
     * currentUser a medio hidratar) NO debe borrar el dueño previo.
     */
    public function test_sync_does_not_null_out_existing_user_id(): void
    {
        $user = $this->makeEmployee();
        $this->seedTaskAndAssignment($user, 501, 9001);

        $response = $this->actingAs($user)->postJson('/api/v1/sync/tasks', [
            'assignments' => [
                [
                    'id' => 501,
                    'task_id' => 9001,
                    'user_id' => null, // estado degradado del cliente
                    'status' => 'in_progress',
                ],
            ],
        ]);

        $response->assertStatus(200);

        // El dueño se conserva; la asignación sigue visible para el empleado.
        $this->assertDatabaseHas('task_assignments', [
            'id' => 501,
            'user_id' => $user->id,
            'status' => 'in_progress',
        ]);

        $list = $this->actingAs($user)->getJson('/api/v1/task-assignments');
        $list->assertStatus(200);
        $this->assertCount(1, $list->json());
    }

    /**
     * §62: una asignación NUEVA sin dueño válido no se crea (no se genera un huérfano
     * invisible), pero el resto del lote sí se procesa.
     */
    public function test_sync_skips_new_orphan_assignment_but_processes_rest(): void
    {
        $user = $this->makeEmployee();
        $this->seedTaskAndAssignment($user, 601, 9101);

        $response = $this->actingAs($user)->postJson('/api/v1/sync/tasks', [
            'assignments' => [
                [
                    'id' => 601,
                    'task_id' => 9101,
                    'user_id' => $user->id,
                    'status' => 'in_progress',
                ],
                [
                    'id' => 999, // nueva, sin dueño → se omite
                    'task_id' => 9101,
                    'user_id' => null,
                    'status' => 'pending',
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', ['id' => 601, 'status' => 'in_progress']);
        $this->assertDatabaseMissing('task_assignments', ['id' => 999]);
    }
}
