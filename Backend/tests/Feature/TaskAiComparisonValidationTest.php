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

class TaskAiComparisonValidationTest extends TestCase
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

    private function makeEmployee(int $tenureDays): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'hire_date' => now()->subDays($tenureDays)->format('Y-m-d'),
            'base_salary' => 300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function makeAiTask(): Task
    {
        return Task::create([
            'title' => 'Reponer anaquel de dulces',
            'tenant_id' => 1,
            'validation_mode' => 'ai_comparison',
            'ai_comparison_enabled' => true,
            'ai_reference_images' => ['data:image/jpeg;base64,REF1', 'data:image/jpeg;base64,REF2'],
            'ai_tolerance_description' => 'Deben verse al menos 8 de 10 piezas.',
            'points' => 12,
        ]);
    }

    private function makeAssignment(Task $task, User $employee, string $id): TaskAssignment
    {
        return TaskAssignment::create([
            'id' => $id, 'task_id' => $task->id, 'user_id' => $employee->id,
            'status' => 'in_progress', 'tenant_id' => 1, 'date' => now()->toDateString(),
            'accumulated_mins' => 10,
        ]);
    }

    public function test_new_hire_is_always_reviewed_by_a_human_without_calling_gemini(): void
    {
        $employee = $this->makeEmployee(10); // <30 días
        $task = $this->makeAiTask();
        $assignment = $this->makeAssignment($task, $employee, 'ai-1');

        $this->mock(GeminiAIService::class, function ($mock) {
            $mock->shouldNotReceive('compareTaskEvidence');
        });

        $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/ai-validate", [
            'evidence_photo_base64' => 'data:image/jpeg;base64,EVID',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'status' => 'awaiting_validation', 'reviewed_by' => 'human_spotcheck']);
    }

    public function test_veteran_employee_ai_match_completes_and_pays(): void
    {
        $employee = $this->makeEmployee(200); // >90 días
        $task = $this->makeAiTask();

        $this->mock(GeminiAIService::class, function ($mock) {
            $mock->shouldReceive('compareTaskEvidence')->andReturn([
                'match' => true, 'confidence' => 0.91, 'reasoning' => 'Coincide con la referencia.',
            ]);
        });

        $sawAiReview = false;
        for ($i = 0; $i < 25 && !$sawAiReview; $i++) {
            $assignment = $this->makeAssignment($task, $employee, "ai-veteran-match-{$i}");

            $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/ai-validate", [
                'evidence_photo_base64' => 'data:image/jpeg;base64,EVID',
            ]);
            $response->assertStatus(200);

            if ($response->json('reviewed_by') === 'ai') {
                $sawAiReview = true;
                $response->assertJson(['status' => 'completed']);
                $this->assertDatabaseHas('task_assignments', [
                    'id' => $assignment->id, 'status' => 'completed', 'points_awarded' => 12,
                ]);
                $wallet = DB::table('user_wallets')->where('user_id', $employee->id)->first();
                $this->assertNotNull($wallet);
                $this->assertGreaterThan(0, (float) $wallet->balance_coins);
            }
        }

        $this->assertTrue($sawAiReview, 'Con 90% de probabilidad para veteranos, al menos 1 de 25 intentos debió pasar por IA.');
    }

    public function test_veteran_employee_ai_no_match_sends_to_awaiting_validation_with_reasoning(): void
    {
        $employee = $this->makeEmployee(200);
        $task = $this->makeAiTask();

        $this->mock(GeminiAIService::class, function ($mock) {
            $mock->shouldReceive('compareTaskEvidence')->andReturn([
                'match' => false, 'confidence' => 0.3, 'reasoning' => 'Faltan piezas en el anaquel.',
            ]);
        });

        $sawAiReview = false;
        for ($i = 0; $i < 25 && !$sawAiReview; $i++) {
            $assignment = $this->makeAssignment($task, $employee, "ai-veteran-nomatch-{$i}");

            $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/ai-validate", [
                'evidence_photo_base64' => 'data:image/jpeg;base64,EVID',
            ]);
            $response->assertStatus(200);

            if ($response->json('reviewed_by') === 'ai') {
                $sawAiReview = true;
                $this->assertDatabaseHas('task_assignments', [
                    'id' => $assignment->id,
                    'status' => 'awaiting_validation',
                    'validation_feedback' => 'Faltan piezas en el anaquel.',
                ]);
            }
        }

        $this->assertTrue($sawAiReview);
    }

    public function test_veteran_employee_gemini_failure_degrades_gracefully(): void
    {
        $employee = $this->makeEmployee(200);
        $task = $this->makeAiTask();

        $this->mock(GeminiAIService::class, function ($mock) {
            $mock->shouldReceive('compareTaskEvidence')->andThrow(new \Exception('GEMINI_API_KEY no configurada.'));
        });

        $sawAiFailure = false;
        for ($i = 0; $i < 25 && !$sawAiFailure; $i++) {
            $assignment = $this->makeAssignment($task, $employee, "ai-veteran-fail-{$i}");

            $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/ai-validate", [
                'evidence_photo_base64' => 'data:image/jpeg;base64,EVID',
            ]);
            $response->assertStatus(200);

            if ($response->json('reviewed_by') === 'ai_unavailable') {
                $sawAiFailure = true;
                $this->assertDatabaseHas('task_assignments', [
                    'id' => $assignment->id,
                    'status' => 'awaiting_validation',
                ]);
            }
        }

        $this->assertTrue($sawAiFailure);
    }

    public function test_ai_validate_rejects_a_task_without_ai_comparison_enabled(): void
    {
        $employee = $this->makeEmployee(200);
        $task = Task::create(['title' => 'Tarea normal', 'tenant_id' => 1, 'validation_mode' => 'auto']);
        $assignment = $this->makeAssignment($task, $employee, 'ai-not-enabled');

        $response = $this->actingAs($employee)->postJson("/api/v1/task-assignments/{$assignment->id}/ai-validate", [
            'evidence_photo_base64' => 'data:image/jpeg;base64,EVID',
        ]);

        $response->assertStatus(422);
    }

    public function test_sync_tasks_accepts_and_persists_ai_comparison_fields(): void
    {
        $admin = $this->makeEmployee(10);
        DB::table('users')->where('id', $admin->id)->update(['role' => 'admin']);
        $admin->refresh();

        $response = $this->actingAs($admin)->postJson('/api/v1/sync/tasks', [
            'tasks' => [[
                'id' => 701,
                'title' => 'Tarea con IA',
                'validation_mode' => 'ai_comparison',
                'ai_comparison_enabled' => true,
                'ai_reference_images' => ['data:image/jpeg;base64,A', 'data:image/jpeg;base64,B'],
                'ai_tolerance_description' => 'Tolerancia de prueba',
            ]],
        ]);

        $response->assertStatus(200);

        $task = Task::withoutGlobalScopes()->find(701);
        $this->assertNotNull($task);
        $this->assertTrue($task->ai_comparison_enabled);
        $this->assertEquals(['data:image/jpeg;base64,A', 'data:image/jpeg;base64,B'], $task->ai_reference_images);
        $this->assertEquals('Tolerancia de prueba', $task->ai_tolerance_description);
    }
}
