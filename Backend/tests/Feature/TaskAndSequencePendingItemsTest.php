<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskAndSequencePendingItemsTest extends TestCase
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

        DB::table('tenants')->insertOrIgnore([
            'id' => 2,
            'name' => 'Otra Empresa',
            'subdomain' => 'otra',
            'plan' => 'pro',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeUser(string $role = 'admin', int $tenantId = 1): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        $user->refresh();

        DB::table('employees')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    // §14.1 ------------------------------------------------------------

    public function test_task_sync_populates_date_and_points_awarded_on_completion(): void
    {
        $admin = $this->makeUser();

        $task = Task::create([
            'id' => 501,
            'title' => 'Tarea de prueba',
            'estimated_mins' => 15,
            'points' => 25,
            'priority' => 'normal',
            'category' => 'operativo',
            'target_type' => 'role',
            'tenant_id' => 1,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/sync/tasks', [
            'assignments' => [
                [
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'taskId' => $task->id,
                    'userId' => $admin->id,
                    'status' => 'completed',
                ],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $admin->id,
            'status' => 'completed',
            'points_awarded' => 25,
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->format('Y-m-d'),
        ]);
    }

    public function test_assign_task_populates_date(): void
    {
        $admin = $this->makeUser();
        $employee = $this->makeUser('empleado');

        $task = Task::create([
            'id' => 502,
            'title' => 'Asignación directa',
            'estimated_mins' => 10,
            'points' => 5,
            'priority' => 'normal',
            'category' => 'operativo',
            'target_type' => 'user',
            'tenant_id' => 1,
        ]);

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/dashboard/assign-task', [
            'user_id' => $employee->id,
            'task_id' => $task->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->format('Y-m-d'),
        ]);
    }

    // §14.3 --------------------------------------------------------------

    public function test_create_task_accepts_pool_and_department_target_types(): void
    {
        $admin = $this->makeUser();

        foreach (['pool', 'department'] as $targetType) {
            $response = $this->actingAs($admin)->postJson('/api/v1/admin/dashboard/create-task', [
                'title' => "Tarea $targetType",
                'estimated_mins' => 10,
                'points' => 5,
                'priority' => 'normal',
                'target_type' => $targetType,
            ]);

            $response->assertStatus(200);
        }

        $this->assertDatabaseHas('tasks', ['title' => 'Tarea pool', 'target_type' => 'pool']);
        $this->assertDatabaseHas('tasks', ['title' => 'Tarea department', 'target_type' => 'department']);

        // La asignación inicial queda sin user_id (disponible), igual que para 'role'.
        $this->assertDatabaseHas('task_assignments', [
            'user_id' => null,
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->format('Y-m-d'),
        ]);
    }

    // §14.2 ----------------------------------------------------------------

    public function test_monitor_excludes_active_assignments_from_other_tenants_and_other_days(): void
    {
        $admin = $this->makeUser('admin', 1);

        // getMonitorData filtra usuarios "offline" (sin fichaje hoy) del feed — hace
        // falta un check_in real para que el usuario aparezca con su tarea activa.
        DB::table('time_entries')->insert([
            'user_id' => $admin->id,
            'tenant_id' => 1,
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->format('Y-m-d'),
            'type' => 'check_in',
            'time' => '09:00:00',
            'is_late' => false,
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $myTask = Task::create([
            'id' => 601, 'title' => 'Mi tarea', 'estimated_mins' => 10, 'points' => 5,
            'priority' => 'normal', 'category' => 'operativo', 'target_type' => 'role', 'tenant_id' => 1,
        ]);
        DB::table('task_assignments')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'task_id' => $myTask->id,
            'user_id' => $admin->id,
            'status' => 'in_progress',
            'tenant_id' => 1,
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->format('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Tarea activa de OTRO tenant — nunca debe aparecer en el monitor del tenant 1.
        $otherTask = Task::withoutGlobalScopes()->create([
            'id' => 602, 'title' => 'Tarea de otra empresa', 'estimated_mins' => 10, 'points' => 5,
            'priority' => 'normal', 'category' => 'operativo', 'target_type' => 'role', 'tenant_id' => 2,
        ]);
        DB::table('task_assignments')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'task_id' => $otherTask->id,
            'user_id' => $admin->id,
            'status' => 'in_progress',
            'tenant_id' => 2,
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->format('Y-m-d'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard/monitor');
        $response->assertStatus(200);

        $names = collect($response->json('data.users'))->pluck('active_task.title')->filter()->all();
        $this->assertContains('Mi tarea', $names);
        $this->assertNotContains('Tarea de otra empresa', $names);
    }

    // §36 --------------------------------------------------------------

    public function test_monitor_exposes_hire_date_per_user(): void
    {
        $admin = $this->makeUser('admin', 1);
        DB::table('employees')->where('user_id', $admin->id)->update(['hire_date' => '2026-01-15']);

        DB::table('time_entries')->insert([
            'user_id' => $admin->id,
            'tenant_id' => 1,
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->format('Y-m-d'),
            'type' => 'check_in',
            'time' => '08:00:00',
            'is_late' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard/monitor');
        $response->assertStatus(200);

        $userRow = collect($response->json('data.users'))->firstWhere('id', $admin->id);
        $this->assertNotNull($userRow);
        $this->assertEquals('2026-01-15', $userRow['hire_date']);
    }

    // §15 --------------------------------------------------------------------

    public function test_meal_end_without_meal_start_is_rejected(): void
    {
        $user = $this->makeUser('empleado');
        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1, 'key' => 'time_mode', 'value' => json_encode('simulated'),
        ]);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in', 'time' => '09:00:00',
        ])->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'meal_end', 'time' => '14:00:00',
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString("sin un 'meal_start' previo", $response->json('message'));
    }

    public function test_check_out_without_check_in_is_rejected(): void
    {
        $user = $this->makeUser('empleado');

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_out', 'time' => '18:00:00',
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString("sin un 'check_in' previo", $response->json('message'));
    }

    public function test_meal_start_after_check_out_is_rejected(): void
    {
        // check_in dos veces ya lo bloquea el guard anti-duplicados por sí solo; el caso
        // que realmente prueba "blocked_by check_out" es un type distinto que aún no se
        // había usado ese día, como meal_start.
        $user = $this->makeUser('empleado');
        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1, 'key' => 'time_mode', 'value' => json_encode('simulated'),
        ]);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_in', 'time' => '09:00:00',
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/v1/store-opening/closing-checklist', [
            'user_id' => $user->id,
            'checks' => ['lights_off' => true, 'safe_secured' => true, 'alarm_activated' => true],
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'check_out', 'time' => '18:00:00',
        ])->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id, 'type' => 'meal_start', 'time' => '18:30:00',
        ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('después de cerrar el día', $response->json('message'));
    }

    public function test_valid_sequence_still_works(): void
    {
        $user = $this->makeUser('empleado');
        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1, 'key' => 'time_mode', 'value' => json_encode('simulated'),
        ]);

        $sequence = [
            ['check_in', '09:00:00'],
            ['meal_start', '13:00:00'],
            ['meal_end', '14:00:00'],
            ['break_start', '16:00:00'],
            ['break_end', '16:15:00'],
        ];
        foreach ($sequence as [$type, $time]) {
            $this->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id, 'type' => $type, 'time' => $time,
            ])->assertStatus(200);
        }

        $this->assertDatabaseCount('time_entries', count($sequence));
    }
}
