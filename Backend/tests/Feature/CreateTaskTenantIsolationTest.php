<?php

namespace Tests\Feature;

use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T5 (Tareas): aislamiento de tenant en POST /admin/dashboard/create-task.
 *
 * Con target_type='user', createTask usaba target_id como user_id de la assignment
 * sin verificar que ese usuario perteneciera al tenant del actor (a diferencia de
 * assignTask, que sí lo cierra). Un admin del tenant A podía crear una assignment
 * con user_id de un colaborador del tenant B.
 */
class CreateTaskTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1, 2] as $tid) {
            DB::table('tenants')->insertOrIgnore([
                'id' => $tid,
                'name' => "Tenant {$tid}",
                'subdomain' => "t{$tid}",
                'public_slug' => "t{$tid}",
                'plan' => 'enterprise',
                'max_users' => 20,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function makeUser(int $tenantId, string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        return $user->fresh();
    }

    private function createTask(User $actor, array $overrides = [])
    {
        return $this->actingAs($actor)->postJson('/api/v1/admin/dashboard/create-task', array_merge([
            'title' => 'Tarea T5',
            'estimated_mins' => 15,
            'points' => 10,
            'priority' => 'normal',
        ], $overrides));
    }

    public function test_no_puede_asignar_a_usuario_de_otro_tenant(): void
    {
        $adminA = $this->makeUser(1, 'admin');
        $empleadoB = $this->makeUser(2);

        $response = $this->createTask($adminA, [
            'target_type' => 'user',
            'target_id' => $empleadoB->id,
        ]);

        $response->assertStatus(403);
        // No debe quedar ni tarea ni assignment apuntando al usuario del tenant B.
        $this->assertDatabaseMissing('task_assignments', ['user_id' => $empleadoB->id]);
        $this->assertDatabaseMissing('tasks', ['title' => 'Tarea T5', 'tenant_id' => 1]);
    }

    public function test_no_puede_asignar_a_usuario_inexistente(): void
    {
        $adminA = $this->makeUser(1, 'admin');

        $response = $this->createTask($adminA, [
            'target_type' => 'user',
            'target_id' => 999999,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('tasks', ['title' => 'Tarea T5']);
    }

    public function test_si_puede_asignar_a_usuario_del_propio_tenant(): void
    {
        $adminA = $this->makeUser(1, 'admin');
        $empleadoA = $this->makeUser(1);

        $response = $this->createTask($adminA, [
            'target_type' => 'user',
            'target_id' => $empleadoA->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'user_id' => $empleadoA->id,
            'tenant_id' => 1,
        ]);
    }

    public function test_tarea_a_la_bolsa_por_rol_sigue_funcionando(): void
    {
        $adminA = $this->makeUser(1, 'admin');

        $response = $this->createTask($adminA, [
            'target_type' => 'role',
            'target_id' => 5,
        ]);

        $response->assertStatus(200);
        // Assignment de bolsa: user_id null.
        $this->assertDatabaseHas('task_assignments', [
            'user_id' => null,
            'tenant_id' => 1,
        ]);
    }
}
