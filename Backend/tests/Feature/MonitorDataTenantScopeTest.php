<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T8 (Tareas): aislamiento de tenant en GET /admin/dashboard/monitor.
 *
 * getMonitorData tenía queries Eloquent sin where('tenant_id') que confiaban en el
 * TenantScope global (que se apaga en consola y para platform_admin): Task::select
 * ->get() (available_tasks, devuelto al cliente), Employee::where, TaskAssignment::
 * whereIn. Además mapeaba tenant_id con ?? 1 (admin sin tenant → tenant 1).
 */
class MonitorDataTenantScopeTest extends TestCase
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

    private function makeUser(?int $tenantId, string $role): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        return $user->fresh();
    }

    private function makeTask(int $id, int $tenantId, string $title): void
    {
        Task::create(['id' => $id, 'title' => $title]);
        DB::table('tasks')->where('id', $id)->update(['tenant_id' => $tenantId]);
    }

    public function test_available_tasks_no_filtra_tareas_de_otro_tenant(): void
    {
        $adminA = $this->makeUser(1, 'admin');
        $this->makeTask(800, 1, 'Tarea propia A');
        $this->makeTask(801, 2, 'Tarea ajena B');

        $response = $this->actingAs($adminA)->getJson('/api/v1/admin/dashboard/monitor');
        $response->assertStatus(200);

        $titles = collect($response->json('data.available_tasks'))->pluck('title')->all();
        $this->assertContains('Tarea propia A', $titles);
        $this->assertNotContains('Tarea ajena B', $titles);
    }

    public function test_admin_sin_tenant_no_ve_el_monitor_del_tenant_1(): void
    {
        $adminSinTenant = $this->makeUser(null, 'admin');
        $this->makeTask(802, 1, 'Tarea del tenant 1');

        $response = $this->actingAs($adminSinTenant)->getJson('/api/v1/admin/dashboard/monitor');

        $response->assertStatus(403);
    }
}
