<?php

namespace Tests\Feature;

use App\Models\JobRole;
use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\GeminiAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuggestWorkPlanTest extends TestCase
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

    private function makeUser(?int $jobRoleId, string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'job_role_id' => $jobRoleId,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function checkIn(User $user): void
    {
        DB::table('time_entries')->insert([
            'user_id' => $user->id,
            'tenant_id' => 1,
            'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->format('Y-m-d'),
            'type' => 'check_in',
            'time' => '08:00:00',
            'is_late' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_suggest_work_plan_gathers_context_and_returns_ai_suggestions(): void
    {
        $role = JobRole::create(['name' => 'Cajero', 'tenant_id' => 1, 'area' => 'Ops']);
        $admin = $this->makeUser($role->id, 'admin');
        $present = $this->makeUser($role->id);
        $absent = $this->makeUser($role->id);

        $this->checkIn($present);
        // $absent no ficha → debe caer en la lista de ausentes.

        $task = Task::create(['title' => 'Tarea pendiente', 'tenant_id' => 1, 'validation_mode' => 'auto']);
        TaskAssignment::create([
            'id' => 'wp-1', 'task_id' => $task->id, 'user_id' => $present->id,
            'status' => 'pending', 'tenant_id' => 1, 'date' => \Carbon\Carbon::now(\App\Helpers\TenantTimezone::for(1))->toDateString(),
        ]);

        \App\Models\ObsidianDocument::withoutGlobalScopes()->create([
            'tenant_id' => 1, 'slug' => 'manual-de-caja', 'filename' => 'manual-de-caja.md',
            'title' => 'Manual de Caja', 'type' => 'sop', 'content' => 'El cajero abre la caja y hace el arqueo.',
            'raw_content' => 'El cajero abre la caja y hace el arqueo.',
        ]);

        $this->mock(GeminiAIService::class, function ($mock) use ($present) {
            $mock->shouldReceive('suggestWorkPlan')
                ->once()
                ->withArgs(function ($presentArg, $absentArg, $pendingArg, $rolesArg, $vaultArg) use ($present) {
                    $presentIds = array_column($presentArg, 'user_id');
                    return in_array($present->id, $presentIds)
                        && count($absentArg) >= 1
                        && count($pendingArg) === 1
                        && count($rolesArg) === 1
                        && str_contains($vaultArg, 'Manual de Caja');
                })
                ->andReturn([
                    'ai_available' => true,
                    'summary' => 'Falta un cajero; redistribuir.',
                    'staffing_gap_detected' => true,
                    'suggestions' => [
                        ['task_assignment_id' => 'wp-1', 'suggested_target_type' => 'role', 'suggested_target_id' => 6, 'reason' => 'x'],
                    ],
                ]);
        });

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/dashboard/suggest-work-plan', []);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'ai_available' => true,
            'staffing_gap_detected' => true,
        ]);
        $response->assertJsonCount(1, 'suggestions');
    }

    public function test_suggest_work_plan_degrades_gracefully_when_ai_unavailable(): void
    {
        $role = JobRole::create(['name' => 'Cajero', 'tenant_id' => 1, 'area' => 'Ops']);
        $admin = $this->makeUser($role->id, 'admin');

        $this->mock(GeminiAIService::class, function ($mock) {
            $mock->shouldReceive('suggestWorkPlan')->once()->andReturn([
                'ai_available' => false,
                'summary' => null,
                'staffing_gap_detected' => false,
                'suggestions' => [],
            ]);
        });

        $response = $this->actingAs($admin)->postJson('/api/v1/admin/dashboard/suggest-work-plan', []);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'ai_available' => false,
            'suggestions' => [],
        ]);
    }

    public function test_suggest_work_plan_requires_admin_or_supervisor_role(): void
    {
        $role = JobRole::create(['name' => 'Cajero', 'tenant_id' => 1, 'area' => 'Ops']);
        $employee = $this->makeUser($role->id, 'empleado');

        $response = $this->actingAs($employee)->postJson('/api/v1/admin/dashboard/suggest-work-plan', []);

        $response->assertStatus(403);
    }
}
