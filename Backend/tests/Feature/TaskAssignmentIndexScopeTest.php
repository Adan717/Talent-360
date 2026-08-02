<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T3 (Tareas): scope de lectura en GET /task-assignments.
 *
 * Antes, con ?user_id= un empleado leía las assignments de cualquier compañero
 * del tenant (IDOR de lectura: agenda/tiempos/feedback ajenos). Ningún flujo del
 * FE pasa ?user_id= (RelojVisual siempre pide las propias). Reglas:
 *  - No privilegiado: ?user_id= se ignora, siempre se filtra a las propias.
 *  - admin/supervisor/platform_admin: pueden consultar cualquier user_id de su tenant.
 */
class TaskAssignmentIndexScopeTest extends TestCase
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

    private function seedAssignment(string $id, int $userId, int $taskId): void
    {
        TaskAssignment::create([
            'id' => $id,
            'task_id' => $taskId,
            'user_id' => $userId,
            'status' => 'in_progress',
            // H25: la fecha se siembra con la zona del TENANT, que es como la escribe producción
            // (`triggerOpeningChecklist` y el sync). Con `now()` a secas —UTC— este test sólo
            // pasaba fuera de la franja 00:00–06:00 UTC, que es cuando el día del servidor y el
            // del tenant dejan de coincidir.
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->format('Y-m-d'),
            'tenant_id' => 1,
        ]);
    }

    public function test_empleado_solo_ve_sus_propias_aunque_pida_otro_user_id(): void
    {
        $empleado = $this->makeUser();
        $companero = $this->makeUser();
        $task = Task::create(['id' => 300, 'title' => 'T', 'tenant_id' => 1]);

        $this->seedAssignment('mia-1', $empleado->id, $task->id);
        $this->seedAssignment('ajena-1', $companero->id, $task->id);

        $response = $this->actingAs($empleado)
            ->getJson("/api/v1/task-assignments?user_id={$companero->id}");

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains('mia-1', $ids);
        $this->assertNotContains('ajena-1', $ids);
    }

    public function test_empleado_sin_param_ve_solo_las_propias(): void
    {
        $empleado = $this->makeUser();
        $companero = $this->makeUser();
        $task = Task::create(['id' => 301, 'title' => 'T', 'tenant_id' => 1]);

        $this->seedAssignment('mia-2', $empleado->id, $task->id);
        $this->seedAssignment('ajena-2', $companero->id, $task->id);

        $response = $this->actingAs($empleado)->getJson('/api/v1/task-assignments');

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertEquals(['mia-2'], $ids);
    }

    public function test_supervisor_si_puede_consultar_otro_user_id(): void
    {
        $supervisor = $this->makeUser('supervisor');
        $empleado = $this->makeUser();
        $task = Task::create(['id' => 302, 'title' => 'T', 'tenant_id' => 1]);

        $this->seedAssignment('del-emp-1', $empleado->id, $task->id);

        $response = $this->actingAs($supervisor)
            ->getJson("/api/v1/task-assignments?user_id={$empleado->id}");

        $response->assertStatus(200);
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains('del-emp-1', $ids);
    }
}
