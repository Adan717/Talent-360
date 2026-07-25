<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T7 (Tareas): aislamiento de tenant en POST /admin/assignments/{id}/validate.
 *
 * validateAssignment resolvía el assignment con findOrFail($id) confiando solo en
 * el TenantScope global. Este test caracteriza/afianza que un admin/supervisor del
 * tenant A NO puede validar una assignment del tenant B, y el fix añade el
 * where('tenant_id') explícito (defensa en profundidad, consistente con el resto
 * del módulo) para no depender de que el scope global esté activo.
 */
class ValidateAssignmentTenantScopeTest extends TestCase
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

    private function makeUser(int $tenantId, string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        return $user->fresh();
    }

    public function test_admin_no_puede_validar_assignment_de_otro_tenant(): void
    {
        $adminA = $this->makeUser(1, 'admin');
        $empleadoB = $this->makeUser(2, 'empleado');

        // Task + assignment del tenant B (forzados por DB, el hook cae a 1 sin auth).
        Task::create(['id' => 700, 'title' => 'Ajena']);
        DB::table('tasks')->where('id', 700)->update(['tenant_id' => 2]);

        TaskAssignment::create([
            'id' => 'val-ajena-1',
            'task_id' => 700,
            'user_id' => $empleadoB->id,
            'status' => 'awaiting_validation',
        ]);
        DB::table('task_assignments')->where('id', 'val-ajena-1')->update(['tenant_id' => 2]);

        // El admin del tenant A intenta validar la tarea del tenant B.
        $response = $this->actingAs($adminA)
            ->postJson('/api/v1/admin/assignments/val-ajena-1/validate', [
                'status' => 'completed',
            ]);

        // No debe poder: ni la valida ni la muta.
        $this->assertContains($response->status(), [403, 404]);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'val-ajena-1',
            'status' => 'awaiting_validation',
            'validated_by' => null,
        ]);
    }

    public function test_admin_sin_tenant_no_cae_a_tenant_1(): void
    {
        // Admin sin tenant_id: NO debe poder validar tareas del tenant 1 por el `?? 1`.
        $adminSinTenant = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $adminSinTenant->id)->update(['tenant_id' => null]);
        $adminSinTenant = $adminSinTenant->fresh();

        $empleado1 = $this->makeUser(1, 'empleado');
        $task = Task::create(['id' => 701, 'title' => 'Del tenant 1', 'tenant_id' => 1]);
        TaskAssignment::create([
            'id' => 'val-t1-1',
            'task_id' => 701,
            'user_id' => $empleado1->id,
            'status' => 'awaiting_validation',
            'tenant_id' => 1,
        ]);

        $response = $this->actingAs($adminSinTenant)
            ->postJson('/api/v1/admin/assignments/val-t1-1/validate', ['status' => 'completed']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'val-t1-1',
            'status' => 'awaiting_validation',
            'validated_by' => null,
        ]);
    }
}
