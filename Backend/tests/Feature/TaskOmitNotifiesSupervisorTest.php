<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskOmitNotifiesSupervisorTest extends TestCase
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

    private function makeUser(?int $jobRoleId, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['role' => 'empleado'], $overrides));
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'job_role_id' => $jobRoleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_omitting_a_task_notifies_the_resolved_supervisor(): void
    {
        $supervisorRole = JobRole::create(['name' => 'Supervisor de Piso', 'tenant_id' => 1, 'area' => 'Ops']);
        $employeeRole = JobRole::create(['name' => 'Cajero', 'tenant_id' => 1, 'area' => 'Ops', 'reports_to_role_id' => $supervisorRole->id]);

        $supervisor = $this->makeUser($supervisorRole->id, ['role' => 'supervisor']);
        $employee = $this->makeUser($employeeRole->id);

        $task = Task::create(['title' => 'Reponer góndola', 'tenant_id' => 1, 'validation_mode' => 'auto']);
        $assignment = TaskAssignment::create([
            'id' => 'omit-1', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'in_progress', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $this->mock(NotificationService::class, function ($mock) use ($supervisor) {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->with($supervisor->id, \Mockery::type('string'), \Mockery::on(function ($body) {
                    return str_contains($body, 'Reponer góndola') && str_contains($body, 'no había producto');
                }));
        });

        $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/omit", [
            'reason' => 'no había producto suficiente',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'omit-1',
            'status' => 'omitted',
            'validation_feedback' => 'no había producto suficiente',
        ]);
    }

    public function test_omitting_a_task_falls_back_to_admin_role_when_no_supervisor_resolves(): void
    {
        $orphanRole = JobRole::create(['name' => 'Puesto sin jefe', 'tenant_id' => 1, 'area' => 'Ops']);
        $employee = $this->makeUser($orphanRole->id);

        $task = Task::create(['title' => 'Tarea suelta', 'tenant_id' => 1, 'validation_mode' => 'auto']);
        $assignment = TaskAssignment::create([
            'id' => 'omit-2', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'in_progress', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        // ENMIENDA merge F3: sendToRole ahora exige tenant_id (fix de seguridad — sin él, el push
        // se difundía a los admins de TODOS los tenants).
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('sendToRole')->once()->with(\Mockery::type('int'), 'admin', \Mockery::type('string'), \Mockery::type('string'));
            $mock->shouldReceive('sendToRole')->once()->with(\Mockery::type('int'), 'platform_admin', \Mockery::type('string'), \Mockery::type('string'));
        });

        $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/omit", []);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => 'omit-2', 'status' => 'omitted']);
    }
}
