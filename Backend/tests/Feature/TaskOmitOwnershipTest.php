<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M1 (auditoría 2026-07-27): POST /task-assignments/{id}/omit (§34) sólo scopeaba tenant —
 * cualquier empleado podía OMITIR la tarea de cualquier compañero (sabotaje silencioso), y
 * el aviso al supervisor salía a nombre del DUEÑO de la tarea, sin rastro de quién la
 * omitió en realidad.
 *
 * Reglas de esta ronda (mismos patrones del módulo):
 *  1. No privilegiado sólo omite SUS propias asignaciones (403 si no).
 *  2. validated_by registra al ACTOR (quién omitió), y el aviso lo nombra cuando
 *     no es el dueño.
 */
class TaskOmitOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'DecorArte', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeUser(string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->fresh();
    }

    private function makeAssignment(Task $task, ?int $userId): TaskAssignment
    {
        return TaskAssignment::create([
            'id' => 'omit-' . uniqid(),
            'task_id' => $task->id,
            'user_id' => $userId,
            'status' => 'in_progress',
            'tenant_id' => 1,
            'date' => now()->toDateString(),
        ]);
    }

    private function makeTask(int $id): Task
    {
        return Task::create(['id' => $id, 'title' => 'Tarea M1', 'tenant_id' => 1]);
    }

    public function test_empleado_no_puede_omitir_tarea_de_companero(): void
    {
        $empleado = $this->makeUser();
        $companero = $this->makeUser();
        $task = $this->makeTask(1000);
        $assignment = $this->makeAssignment($task, $companero->id);

        $this->actingAs($empleado)
            ->postJson("/api/v1/task-assignments/{$assignment->id}/omit", ['reason' => 'sabotaje'])
            ->assertStatus(403);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_empleado_puede_omitir_la_propia_y_queda_el_rastro(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask(1001);
        $assignment = $this->makeAssignment($task, $empleado->id);

        $this->actingAs($empleado)
            ->postJson("/api/v1/task-assignments/{$assignment->id}/omit", ['reason' => 'Sin material'])
            ->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'omitted',
            'validation_feedback' => 'Sin material',
            'validated_by' => $empleado->id, // rastro del actor
        ]);
    }

    public function test_supervisor_que_omite_tarea_ajena_queda_nombrado_en_el_aviso(): void
    {
        $supervisor = $this->makeUser('supervisor');
        $empleado = $this->makeUser();
        $task = $this->makeTask(1002);
        $assignment = $this->makeAssignment($task, $empleado->id);

        $avisos = [];
        $this->mock(NotificationService::class, function ($mock) use (&$avisos) {
            $mock->shouldReceive('sendToUser')->andReturnUsing(function ($uid, $title, $body) use (&$avisos) {
                $avisos[] = $body;
                return true;
            });
            $mock->shouldReceive('sendToRole')->andReturnUsing(function ($tid, $role, $title, $body) use (&$avisos) {
                $avisos[] = $body;
                return true;
            });
        });

        $this->actingAs($supervisor)
            ->postJson("/api/v1/task-assignments/{$assignment->id}/omit", ['reason' => 'Cierre anticipado'])
            ->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'omitted',
            'validated_by' => $supervisor->id,
        ]);

        // El aviso nombra al ACTOR (supervisor), no atribuye la omisión al dueño.
        $this->assertNotEmpty($avisos);
        $bodies = implode(' | ', $avisos);
        $this->assertStringContainsString($supervisor->name, $bodies);
    }
}
