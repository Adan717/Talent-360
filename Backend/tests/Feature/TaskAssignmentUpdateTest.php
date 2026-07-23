<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskAssignmentUpdateTest extends TestCase
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

    private function makeEmployeeWithSupervisorChain(): User
    {
        $employee = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $employee->id)->update(['tenant_id' => 1]);
        $employee->refresh();

        $supervisorRole = JobRole::create(['name' => 'Supervisor', 'tenant_id' => 1, 'area' => 'Ops']);
        $employeeRole = JobRole::create(['name' => 'Employee', 'tenant_id' => 1, 'reports_to_role_id' => $supervisorRole->id, 'area' => 'Ops']);

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $employee->id,
            'name' => $employee->name,
            'email' => $employee->email,
            'hire_date' => now()->subDays(200)->format('Y-m-d'),
            'job_role_id' => $employeeRole->id,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $employee;
    }

    public function test_completing_an_auto_validation_task_awards_points_and_wallet_coins(): void
    {
        $employee = $this->makeEmployeeWithSupervisorChain();
        $task = Task::create(['title' => 'Auto Task', 'tenant_id' => 1, 'validation_mode' => 'auto', 'points' => 20]);
        $assignment = TaskAssignment::create([
            'id' => 'ta-1', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'in_progress', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($employee)->putJson("/api/v1/task-assignments/{$assignment->id}", [
            'status' => 'completed',
            'accumulated_mins' => 15,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'ta-1',
            'status' => 'completed',
            'points_awarded' => 20,
            'coins_awarded' => 2.0,
        ]);

        $wallet = DB::table('user_wallets')->where('user_id', $employee->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(2.0, (float) $wallet->balance_coins);
    }

    public function test_completing_a_forced_validation_task_downgrades_to_awaiting_validation(): void
    {
        $employee = $this->makeEmployeeWithSupervisorChain();
        $task = Task::create(['title' => 'Forced Task', 'tenant_id' => 1, 'validation_mode' => 'forced', 'points' => 15]);
        $assignment = TaskAssignment::create([
            'id' => 'ta-2', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'in_progress', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($employee)->putJson("/api/v1/task-assignments/{$assignment->id}", [
            'status' => 'completed',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'ta-2',
            'status' => 'awaiting_validation',
        ]);
        // No se paga hasta que un supervisor valide.
        $this->assertDatabaseMissing('user_wallets', ['user_id' => $employee->id]);
    }

    public function test_already_completed_assignment_is_not_repaid_on_further_updates(): void
    {
        $employee = $this->makeEmployeeWithSupervisorChain();
        $task = Task::create(['title' => 'Auto Task 2', 'tenant_id' => 1, 'validation_mode' => 'auto', 'points' => 10]);
        $assignment = TaskAssignment::create([
            'id' => 'ta-3', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'completed', 'points_awarded' => 10, 'coins_awarded' => 1.0,
            'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);
        $wallet = \App\Models\UserWallet::getOrCreateForUser($employee->id, 1);
        $wallet->deposit(1.0, 10, 'earned_task', 'Recompensa inicial', 'TaskAssignment', $assignment->id);

        $response = $this->actingAs($employee)->putJson("/api/v1/task-assignments/{$assignment->id}", [
            'status' => 'completed',
            'accumulated_mins' => 999,
        ]);

        $response->assertStatus(200);

        $walletAfter = DB::table('user_wallets')->where('user_id', $employee->id)->first();
        $this->assertEquals(1.0, (float) $walletAfter->balance_coins, 'No debe pagarse dos veces la misma asignación.');
    }

    public function test_completing_an_assignment_whose_task_belongs_to_another_tenant_does_not_crash(): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => 2, 'name' => 'Otro Tenant', 'subdomain' => 'otro', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherTenantTask = Task::withoutGlobalScopes()->create([
            'title' => 'Tarea de otro tenant', 'tenant_id' => 2, 'validation_mode' => 'auto',
        ]);

        $employee = $this->makeEmployeeWithSupervisorChain();
        $assignment = TaskAssignment::withoutGlobalScopes()->create([
            'id' => 'ta-4', 'task_id' => $otherTenantTask->id, 'user_id' => $employee->id,
            'status' => 'in_progress', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($employee)->putJson("/api/v1/task-assignments/{$assignment->id}", [
            'status' => 'completed',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'ta-4',
            'status' => 'completed',
            'points_awarded' => 10,
        ]);
    }

    public function test_update_accepts_and_persists_origin(): void
    {
        $employee = $this->makeEmployeeWithSupervisorChain();
        $task = Task::create(['title' => 'Extra Task', 'tenant_id' => 1, 'validation_mode' => 'auto']);
        $assignment = TaskAssignment::create([
            'id' => 'ta-5', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'pending', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($employee)->putJson("/api/v1/task-assignments/{$assignment->id}", [
            'status' => 'in_progress',
            'origin' => 'extra',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => 'ta-5', 'origin' => 'extra']);
    }

    public function test_get_task_assignments_returns_origin(): void
    {
        $employee = $this->makeEmployeeWithSupervisorChain();
        $task = Task::create(['title' => 'Carried Task', 'tenant_id' => 1, 'validation_mode' => 'auto']);
        TaskAssignment::create([
            'id' => 'ta-6', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'pending', 'tenant_id' => 1, 'date' => now()->toDateString(),
            'origin' => 'carried_over',
        ]);

        $response = $this->actingAs($employee)->getJson('/api/v1/task-assignments?date=' . now()->toDateString());

        $response->assertStatus(200);
        $row = collect($response->json())->firstWhere('id', 'ta-6');
        $this->assertNotNull($row);
        $this->assertEquals('carried_over', $row['origin']);
    }
}
