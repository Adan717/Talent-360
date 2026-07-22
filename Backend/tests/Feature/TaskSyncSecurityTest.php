<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskSyncSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'pro',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['role' => 'empleado'], $overrides));
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    public function test_employee_cannot_create_a_task_via_sync(): void
    {
        $employee = $this->makeUser();

        $response = $this->actingAs($employee)->postJson('/api/v1/sync/tasks', [
            'tasks' => [
                ['id' => 501, 'title' => 'Tarea inventada por un empleado'],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('tasks', ['id' => 501]);
    }

    public function test_employee_cannot_edit_routines_via_sync(): void
    {
        $employee = $this->makeUser();

        $response = $this->actingAs($employee)->postJson('/api/v1/sync/tasks', [
            'routines' => [
                ['id' => 501, 'title' => 'Rutina inventada', 'trigger' => 'manual', 'assignMode' => 'role'],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_employee_can_still_sync_only_assignments(): void
    {
        $employee = $this->makeUser();
        $task = Task::create(['title' => 'Tarea existente', 'tenant_id' => 1, 'validation_mode' => 'auto']);

        $response = $this->actingAs($employee)->postJson('/api/v1/sync/tasks', [
            'assignments' => [
                [
                    'id' => 'assign-sec-1',
                    'task_id' => $task->id,
                    'user_id' => $employee->id,
                    'status' => 'in_progress',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => 'assign-sec-1', 'status' => 'in_progress']);
    }

    public function test_admin_can_create_tasks_via_sync(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson('/api/v1/sync/tasks', [
            'tasks' => [
                ['id' => 502, 'title' => 'Tarea creada por admin'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('tasks', ['id' => 502, 'title' => 'Tarea creada por admin', 'tenant_id' => 1]);
    }

    public function test_completing_an_assignment_whose_task_belongs_to_another_tenant_does_not_crash(): void
    {
        // §32: task_assignments.task_id tiene FK a tasks.id (satisfecha porque la fila
        // existe físicamente), pero Task::find() está scoped por TenantScope — si el
        // taskId apunta a una tarea de OTRO tenant (mismo id numérico, ids globales no
        // particionados por tenant), Task::find() da null aunque la FK sea válida. Este
        // es exactamente el escenario real del placeholder taskId:9999 de Ley Silla:
        // "inofensivo hasta que se completa" porque el INSERT pasa (FK ok) pero
        // $task->points sin null-safe tronaba al calcular puntos.
        DB::table('tenants')->insertOrIgnore([
            'id' => 2, 'name' => 'Otro Tenant', 'subdomain' => 'otro', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherTenantTask = Task::withoutGlobalScopes()->create([
            'title' => 'Tarea de otro tenant', 'tenant_id' => 2, 'validation_mode' => 'auto',
        ]);

        $employee = $this->makeUser();

        $response = $this->actingAs($employee)->postJson('/api/v1/sync/tasks', [
            'assignments' => [
                [
                    'id' => 'assign-sec-2',
                    'task_id' => $otherTenantTask->id,
                    'user_id' => $employee->id,
                    'status' => 'completed',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'assign-sec-2',
            'status' => 'completed',
            'points_awarded' => 10,
        ]);
    }

    public function test_migration_seeded_the_real_silla_monitoring_task_for_the_tenant(): void
    {
        $this->assertDatabaseHas('tasks', [
            'tenant_id' => 1,
            'title' => 'Monitoreo de seguridad desde silla',
            'can_be_done_sitting' => true,
        ]);
    }
}
