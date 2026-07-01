<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\JobRole;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Jobs\LogTaskValidationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskValidationRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Seed Default Tenant
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

    public function test_prevent_self_validation(): void
    {
        $employee = User::factory()->create([
            'role' => 'supervisor',
        ]);
        DB::table('users')->where('id', $employee->id)->update(['tenant_id' => 1]);

        $task = Task::create([
            'title' => 'Test Task',
            'tenant_id' => 1,
            'validation_mode' => 'forced',
        ]);

        $assignment = TaskAssignment::create([
            'id' => 'assign-1',
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'status' => 'awaiting_validation',
            'tenant_id' => 1,
        ]);

        $response = $this->actingAs($employee)
            ->postJson("/api/v1/admin/assignments/{$assignment->id}/validate", [
                'status' => 'completed',
            ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'No puedes validar tus propias tareas.']);
    }

    public function test_hierarchical_supervisor_validation_success(): void
    {
        Queue::fake();

        // Create roles in reporting tree: Supervisor reports to nothing, Employee reports to Supervisor
        $supervisorRole = JobRole::create([
            'name' => 'Supervisor',
            'tenant_id' => 1,
            'reports_to_role_id' => null,
            'area' => 'Management',
        ]);

        $employeeRole = JobRole::create([
            'name' => 'Empleado',
            'tenant_id' => 1,
            'reports_to_role_id' => $supervisorRole->id,
            'area' => 'Operations',
        ]);

        $supervisor = User::factory()->create([
            'role' => 'supervisor',
        ]);
        DB::table('users')->where('id', $supervisor->id)->update(['tenant_id' => 1]);
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $supervisor->id,
            'name' => $supervisor->name,
            'email' => $supervisor->email,
            'job_role_id' => $supervisorRole->id,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employee = User::factory()->create([
            'role' => 'empleado',
        ]);
        DB::table('users')->where('id', $employee->id)->update(['tenant_id' => 1]);
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'job_role_id' => $employeeRole->id,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = Task::create([
            'title' => 'Clean Windows',
            'tenant_id' => 1,
            'validation_mode' => 'forced',
        ]);

        $assignment = TaskAssignment::create([
            'id' => 'assign-2',
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'status' => 'awaiting_validation',
            'tenant_id' => 1,
        ]);

        $response = $this->actingAs($supervisor)
            ->postJson("/api/v1/admin/assignments/{$assignment->id}/validate", [
                'status' => 'completed',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Tarea aprobada con éxito.',
            'status' => 'completed'
        ]);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'assign-2',
            'status' => 'completed',
            'validated_by' => $supervisor->id,
        ]);

        Queue::assertPushed(LogTaskValidationJob::class);
    }

    public function test_hierarchical_validation_fails_for_unauthorized_user(): void
    {
        $roleA = JobRole::create([
            'name' => 'Supervisor A',
            'tenant_id' => 1,
            'area' => 'Finance',
        ]);

        $roleB = JobRole::create([
            'name' => 'Supervisor B',
            'tenant_id' => 1,
            'area' => 'Marketing',
        ]);

        $supervisorA = User::factory()->create([
            'role' => 'supervisor',
        ]);
        DB::table('users')->where('id', $supervisorA->id)->update(['tenant_id' => 1]);
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $supervisorA->id,
            'name' => $supervisorA->name,
            'email' => $supervisorA->email,
            'job_role_id' => $roleA->id,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employee = User::factory()->create([
            'role' => 'empleado',
        ]);
        DB::table('users')->where('id', $employee->id)->update(['tenant_id' => 1]);
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'job_role_id' => $roleB->id,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = Task::create([
            'title' => 'Admin Task',
            'tenant_id' => 1,
            'validation_mode' => 'forced',
        ]);

        $assignment = TaskAssignment::create([
            'id' => 'assign-3',
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'status' => 'awaiting_validation',
            'tenant_id' => 1,
        ]);

        $response = $this->actingAs($supervisorA)
            ->postJson("/api/v1/admin/assignments/{$assignment->id}/validate", [
                'status' => 'completed',
            ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'No tienes permisos de supervisor para validar esta tarea.']);
    }

    public function test_admin_validation_success(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'tenant_id' => 1,
        ]);

        $employee = User::factory()->create([
            'role' => 'empleado',
            'tenant_id' => 1,
        ]);

        $task = Task::create([
            'title' => 'Test Task',
            'tenant_id' => 1,
            'validation_mode' => 'forced',
        ]);

        $assignment = TaskAssignment::create([
            'id' => 'assign-4',
            'task_id' => $task->id,
            'user_id' => $employee->id,
            'status' => 'awaiting_validation',
            'tenant_id' => 1,
        ]);

        $response = $this->actingAs($admin)
            ->postJson("/api/v1/admin/assignments/{$assignment->id}/validate", [
                'status' => 'completed',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'assign-4',
            'status' => 'completed',
        ]);
    }

    public function test_sync_with_auto_validation_mode_completes_immediately(): void
    {
        $employee = User::factory()->create([
            'role' => 'empleado',
        ]);
        DB::table('users')->where('id', $employee->id)->update([
            'tenant_id' => 1,
        ]);

        // Job role with supervisor configured to trigger supervisor validation normally
        $supervisorRole = JobRole::create(['name' => 'Supervisor', 'tenant_id' => 1, 'area' => 'Support']);
        $employeeRole = JobRole::create(['name' => 'Employee', 'tenant_id' => 1, 'reports_to_role_id' => $supervisorRole->id, 'area' => 'Support']);
        
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'hire_date' => now()->subDays(10)->format('Y-m-d'),
            'job_role_id' => $employeeRole->id,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Task with validation mode 'auto'
        $task = Task::create([
            'id' => 101,
            'title' => 'Auto Task',
            'tenant_id' => 1,
            'validation_mode' => 'auto',
            'priority' => 'normal',
        ]);

        $syncData = [
            'tasks' => [
                [
                    'id' => 101,
                    'title' => 'Auto Task',
                    'validation_mode' => 'auto',
                    'priority' => 'normal',
                ]
            ],
            'assignments' => [
                [
                    'id' => 'assign-sync-1',
                    'task_id' => 101,
                    'user_id' => $employee->id,
                    'status' => 'completed',
                ]
            ]
        ];

        $response = $this->actingAs($employee)->postJson('/api/v1/sync/tasks', $syncData);
        $response->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'assign-sync-1',
            'status' => 'completed', // auto mode skips awaiting_validation
        ]);
    }

    public function test_sync_with_forced_validation_mode_requires_validation(): void
    {
        $employee = User::factory()->create([
            'role' => 'empleado',
        ]);
        DB::table('users')->where('id', $employee->id)->update([
            'tenant_id' => 1,
        ]);

        // Job role with supervisor configured to trigger supervisor validation
        $supervisorRole = JobRole::create(['name' => 'Supervisor', 'tenant_id' => 1, 'area' => 'Engineering']);
        $employeeRole = JobRole::create(['name' => 'Employee', 'tenant_id' => 1, 'reports_to_role_id' => $supervisorRole->id, 'area' => 'Engineering']);
        
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'job_role_id' => $employeeRole->id,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = Task::create([
            'id' => 102,
            'title' => 'Forced Task',
            'tenant_id' => 1,
            'validation_mode' => 'forced',
            'priority' => 'normal',
        ]);

        $syncData = [
            'tasks' => [
                [
                    'id' => 102,
                    'title' => 'Forced Task',
                    'validation_mode' => 'forced',
                    'priority' => 'normal',
                ]
            ],
            'assignments' => [
                [
                    'id' => 'assign-sync-2',
                    'task_id' => 102,
                    'user_id' => $employee->id,
                    'status' => 'completed',
                ]
            ]
        ];

        $response = $this->actingAs($employee)->postJson('/api/v1/sync/tasks', $syncData);
        $response->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'assign-sync-2',
            'status' => 'awaiting_validation', // forced mode converts completed to awaiting_validation
        ]);
    }

    public function test_sync_with_dynamic_validation_mode_calculates_probability_new_hire(): void
    {
        // New hire has tenure < 30 days. Should ALWAYS require validation (100%).
        $employee = User::factory()->create([
            'role' => 'empleado',
        ]);
        DB::table('users')->where('id', $employee->id)->update([
            'tenant_id' => 1,
        ]);

        $supervisorRole = JobRole::create(['name' => 'Supervisor', 'tenant_id' => 1, 'area' => 'Sales']);
        $employeeRole = JobRole::create(['name' => 'Employee', 'tenant_id' => 1, 'reports_to_role_id' => $supervisorRole->id, 'area' => 'Sales']);

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'hire_date' => now()->subDays(15)->format('Y-m-d'), // 15 days tenure
            'job_role_id' => $employeeRole->id,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = Task::create([
            'id' => 103,
            'title' => 'Dynamic Task New Hire',
            'tenant_id' => 1,
            'validation_mode' => 'dynamic',
            'priority' => 'normal',
        ]);

        $syncData = [
            'tasks' => [
                [
                    'id' => 103,
                    'title' => 'Dynamic Task New Hire',
                    'validation_mode' => 'dynamic',
                    'priority' => 'normal',
                ]
            ],
            'assignments' => [
                [
                    'id' => 'assign-sync-3',
                    'task_id' => 103,
                    'user_id' => $employee->id,
                    'status' => 'completed',
                ]
            ]
        ];

        $response = $this->actingAs($employee)->postJson('/api/v1/sync/tasks', $syncData);
        $response->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'assign-sync-3',
            'status' => 'awaiting_validation', // always awaiting_validation for new hires
        ]);
    }

    public function test_sync_with_dynamic_validation_mode_probabilistic_senior(): void
    {
        // Senior has tenure > 90 days. Should have a 15% chance of requiring validation.
        $employee = User::factory()->create([
            'role' => 'empleado',
        ]);
        DB::table('users')->where('id', $employee->id)->update([
            'tenant_id' => 1,
        ]);

        $supervisorRole = JobRole::create(['name' => 'Supervisor', 'tenant_id' => 1, 'area' => 'Design']);
        $employeeRole = JobRole::create(['name' => 'Employee', 'tenant_id' => 1, 'reports_to_role_id' => $supervisorRole->id, 'area' => 'Design']);

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'hire_date' => now()->subDays(120)->format('Y-m-d'), // 120 days tenure
            'job_role_id' => $employeeRole->id,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = Task::create([
            'id' => 104,
            'title' => 'Dynamic Task Senior',
            'tenant_id' => 1,
            'validation_mode' => 'dynamic',
            'priority' => 'normal',
        ]);

        // Run sync multiple times to observe probabilistic behavior
        $awaitingCount = 0;
        $completedCount = 0;

        for ($i = 0; $i < 50; $i++) {
            $assignId = "assign-sync-senior-{$i}";
            
            $syncData = [
                'assignments' => [
                    [
                        'id' => $assignId,
                        'task_id' => 104,
                        'user_id' => $employee->id,
                        'status' => 'completed',
                    ]
                ]
            ];

            $response = $this->actingAs($employee)->postJson('/api/v1/sync/tasks', $syncData);
            $response->assertStatus(200);

            $assignment = TaskAssignment::withoutGlobalScopes()->where('id', $assignId)->first();
            if ($assignment->status === 'awaiting_validation') {
                $awaitingCount++;
            } else {
                $completedCount++;
            }
        }

        // Expected probability is 15%. Over 50 trials, we expect the number of awaiting_validation
        // assignments to be relatively low (e.g. between 0 and 20), and completed count to be > 0.
        $this->assertGreaterThan(0, $completedCount, 'At least some tasks should be auto-approved for senior employees');
        // Let's assert awaitingCount is less than 35 out of 50 trials (since prob is 15%, 50 * 0.15 = 7.5 expected)
        $this->assertLessThan(35, $awaitingCount, 'Too many tasks were queued for validation for a senior employee');
    }
}
