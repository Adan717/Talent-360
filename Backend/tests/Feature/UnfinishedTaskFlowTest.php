<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnfinishedTaskFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'T', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();
        DB::table('employees')->insert([
            'tenant_id' => 1, 'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'base_salary' => 300, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    private function makeAssignment(User $employee, string $status, string $date, string $id): TaskAssignment
    {
        $task = Task::create(['title' => 'Rellenar góndola', 'tenant_id' => 1, 'points' => 10, 'validation_mode' => 'auto']);
        return TaskAssignment::create([
            'id' => $id, 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => $status, 'tenant_id' => 1, 'date' => $date, 'accumulated_mins' => 20,
        ]);
    }

    public function test_nightly_command_flags_yesterday_unfinished_tasks(): void
    {
        // A5: el corte ahora es con la tz del TENANT (default CDMX). Se congela el reloj a
        // mediodía UTC para que "ayer"/"hoy" coincidan en ambas zonas — sin esto, un run de
        // CI entre 00:00-06:00 UTC haría que el ayer-UTC fuera el hoy-CDMX y flakearía.
        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse(now()->toDateString() . ' 12:00:00', 'UTC'));

        $employee = $this->makeUser();
        // Ayer, quedó en progreso (nunca se cerró).
        $stale = $this->makeAssignment($employee, 'in_progress', now()->subDay()->toDateString(), 'stale-1');
        // Hoy, sigue en progreso legítimamente — NO debe tocarse.
        $today = $this->makeAssignment($employee, 'in_progress', now()->toDateString(), 'today-1');

        $this->artisan('tasks:flag-unfinished')->assertSuccessful();

        $this->assertDatabaseHas('task_assignments', ['id' => 'stale-1', 'status' => 'awaiting_validation', 'flagged_incomplete' => true]);
        $this->assertDatabaseHas('task_assignments', ['id' => 'today-1', 'status' => 'in_progress', 'flagged_incomplete' => false]);
    }

    public function test_approve_completes_and_pays_and_protects_bonus(): void
    {
        $manager = $this->makeUser('admin');
        $employee = $this->makeUser();
        $assignment = $this->makeAssignment($employee, 'awaiting_validation', now()->subDay()->toDateString(), 'inc-1');
        $assignment->update(['flagged_incomplete' => true]);

        $response = $this->actingAs($manager)->postJson('/api/v1/task-assignments/inc-1/resolve-incomplete', [
            'action' => 'approve',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'inc-1', 'status' => 'completed', 'flagged_incomplete' => false, 'points_awarded' => 10,
        ]);
        $wallet = DB::table('user_wallets')->where('user_id', $employee->id)->first();
        $this->assertNotNull($wallet);
        $this->assertEquals(1.0, (float) $wallet->balance_coins);
    }

    public function test_reschedule_moves_task_to_today_as_carried_over(): void
    {
        $manager = $this->makeUser('admin');
        $employee = $this->makeUser();
        $this->makeAssignment($employee, 'awaiting_validation', now()->subDay()->toDateString(), 'inc-2')
            ->update(['flagged_incomplete' => true]);

        $response = $this->actingAs($manager)->postJson('/api/v1/task-assignments/inc-2/resolve-incomplete', [
            'action' => 'reschedule',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => 'inc-2', 'status' => 'pending', 'origin' => 'carried_over',
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->toDateString(), 'flagged_incomplete' => false,
        ]);
    }

    public function test_reject_marks_omitted_without_paying(): void
    {
        $manager = $this->makeUser('admin');
        $employee = $this->makeUser();
        $this->makeAssignment($employee, 'awaiting_validation', now()->subDay()->toDateString(), 'inc-3')
            ->update(['flagged_incomplete' => true]);

        $response = $this->actingAs($manager)->postJson('/api/v1/task-assignments/inc-3/resolve-incomplete', [
            'action' => 'reject',
            'feedback' => 'No se realizó.',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => 'inc-3', 'status' => 'omitted', 'flagged_incomplete' => false]);
        $this->assertDatabaseMissing('user_wallets', ['user_id' => $employee->id]);
    }
}
