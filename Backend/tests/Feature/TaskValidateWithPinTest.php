<?php

namespace Tests\Feature;

use App\Jobs\LogTaskValidationJob;
use App\Models\JobRole;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskValidateWithPinTest extends TestCase
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

    private function makeUser(?int $jobRoleId, ?string $pin = null, array $overrides = []): User
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
            'security_pin' => $pin ? Hash::make($pin) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function setupHierarchy(): array
    {
        $supervisorRole = JobRole::create(['name' => 'Supervisor', 'tenant_id' => 1, 'area' => 'Ops']);
        $employeeRole = JobRole::create(['name' => 'Cajero', 'tenant_id' => 1, 'area' => 'Ops', 'reports_to_role_id' => $supervisorRole->id]);

        $supervisor = $this->makeUser($supervisorRole->id, '4821', ['role' => 'supervisor']);
        $employee = $this->makeUser($employeeRole->id);

        return [$supervisor, $employee];
    }

    public function test_valid_pin_and_authorized_supervisor_completes_and_pays(): void
    {
        Queue::fake();
        [$supervisor, $employee] = $this->setupHierarchy();

        $task = Task::create(['title' => 'Rellenado de góndola', 'tenant_id' => 1, 'validation_mode' => 'forced', 'points' => 10]);
        $assignment = TaskAssignment::create([
            'id' => 'pin-1', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'awaiting_validation', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/validate-with-pin", [
            'supervisor_user_id' => $supervisor->id,
            'pin' => '4821',
            'status' => 'completed',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'status' => 'completed']);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'pin-1', 'status' => 'completed', 'validated_by' => $supervisor->id, 'points_awarded' => 10,
        ]);

        $wallet = DB::table('user_wallets')->where('user_id', $employee->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(1.0, (float) $wallet->balance_coins);

        Queue::assertPushed(LogTaskValidationJob::class);
    }

    public function test_incorrect_pin_is_rejected_with_generic_message(): void
    {
        [$supervisor, $employee] = $this->setupHierarchy();
        $task = Task::create(['title' => 'Tarea', 'tenant_id' => 1, 'validation_mode' => 'forced']);
        $assignment = TaskAssignment::create([
            'id' => 'pin-2', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'awaiting_validation', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/validate-with-pin", [
            'supervisor_user_id' => $supervisor->id,
            'pin' => '0000',
            'status' => 'completed',
        ]);

        $response->assertStatus(422);
        $response->assertJson(['success' => false]);
        $this->assertDatabaseMissing('task_assignments', ['id' => 'pin-2', 'status' => 'completed']);
    }

    public function test_pin_correct_but_supervisor_not_authorized_for_this_employee_is_rejected(): void
    {
        $unrelatedRole = JobRole::create(['name' => 'Otro puesto sin relación', 'tenant_id' => 1, 'area' => 'Otro']);
        $unrelatedSupervisor = $this->makeUser($unrelatedRole->id, '9999', ['role' => 'supervisor']);

        [, $employee] = $this->setupHierarchy();
        $task = Task::create(['title' => 'Tarea', 'tenant_id' => 1, 'validation_mode' => 'forced']);
        $assignment = TaskAssignment::create([
            'id' => 'pin-3', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'awaiting_validation', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/validate-with-pin", [
            'supervisor_user_id' => $unrelatedSupervisor->id,
            'pin' => '9999',
            'status' => 'completed',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('task_assignments', ['id' => 'pin-3', 'status' => 'completed']);
    }

    public function test_rejecting_with_pin_returns_task_to_in_progress_with_feedback(): void
    {
        Queue::fake();
        [$supervisor, $employee] = $this->setupHierarchy();
        $task = Task::create(['title' => 'Tarea', 'tenant_id' => 1, 'validation_mode' => 'forced']);
        $assignment = TaskAssignment::create([
            'id' => 'pin-4', 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'awaiting_validation', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/validate-with-pin", [
            'supervisor_user_id' => $supervisor->id,
            'pin' => '4821',
            'status' => 'in_progress',
            'feedback' => 'Falta acomodar la esquina izquierda.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'pin-4', 'status' => 'in_progress', 'validated_by' => $supervisor->id,
            'validation_feedback' => 'Falta acomodar la esquina izquierda.',
        ]);
    }

    public function test_supervisor_cannot_validate_own_assignment(): void
    {
        [$supervisor] = $this->setupHierarchy();
        DB::table('employees')->where('user_id', $supervisor->id)->update(['security_pin' => Hash::make('4821')]);

        $task = Task::create(['title' => 'Tarea propia', 'tenant_id' => 1, 'validation_mode' => 'forced']);
        $assignment = TaskAssignment::create([
            'id' => 'pin-5', 'task_id' => $task->id, 'user_id' => $supervisor->id,
            'status' => 'awaiting_validation', 'tenant_id' => 1, 'date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($supervisor)->postJson("/api/v1/task-assignments/{$assignment->id}/validate-with-pin", [
            'supervisor_user_id' => $supervisor->id,
            'pin' => '4821',
            'status' => 'completed',
        ]);

        $response->assertStatus(403);
    }
}
