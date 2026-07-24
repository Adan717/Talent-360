<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ronda T9 (Tareas): rechazo de tenant null en los endpoints admin del monitor.
 *
 * assignTask, createTask, parseVoiceTask y sendMessage mapeaban tenant_id ?? 1, así
 * que un admin/supervisor SIN empresa asignada operaba sobre el tenant 1 (DecorArte
 * demo): asignaba/creaba tareas, dictaba por voz e inyectaba chat ahí. Ahora todos
 * rechazan con 403 si el actor no tiene tenant_id.
 */
class MonitorNullTenantGuardTest extends TestCase
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

    private function adminSinTenant(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => null]);
        return $user->fresh();
    }

    public function test_create_task_rechaza_admin_sin_tenant(): void
    {
        $admin = $this->adminSinTenant();

        $this->actingAs($admin)->postJson('/api/v1/admin/dashboard/create-task', [
            'title' => 'Tarea colada',
            'estimated_mins' => 15,
            'points' => 10,
            'priority' => 'normal',
            'target_type' => 'role',
            'target_id' => 5,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('tasks', ['title' => 'Tarea colada']);
    }

    public function test_assign_task_rechaza_admin_sin_tenant(): void
    {
        $admin = $this->adminSinTenant();
        $empleado1 = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $empleado1->id)->update(['tenant_id' => 1]);
        $task = Task::create(['id' => 900, 'title' => 'T', 'tenant_id' => 1]);

        $this->actingAs($admin)->postJson('/api/v1/admin/dashboard/assign-task', [
            'user_id' => $empleado1->id,
            'task_id' => 900,
        ])->assertStatus(403);

        $this->assertDatabaseMissing('task_assignments', ['task_id' => 900]);
    }

    public function test_parse_voice_task_rechaza_admin_sin_tenant(): void
    {
        $admin = $this->adminSinTenant();

        $this->actingAs($admin)->postJson('/api/v1/admin/dashboard/parse-voice-task', [
            'text' => 'Asigna a Juan limpiar la entrada en 30 minutos',
        ])->assertStatus(403);
    }

    public function test_send_message_rechaza_admin_sin_tenant(): void
    {
        $admin = $this->adminSinTenant();

        $this->actingAs($admin)->postJson('/api/v1/admin/dashboard/send-message', [
            'content' => 'Mensaje colado',
            'type' => 'general',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('internal_messages', ['content' => 'Mensaje colado']);
    }
}
