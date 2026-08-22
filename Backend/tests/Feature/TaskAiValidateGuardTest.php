<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\GeminiAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M2 (auditoría 2026-07-27): POST /task-assignments/{id}/ai-validate (§35) sin
 * endurecimiento:
 *  - SIN ownership: cualquier empleado podía someter "evidencia" por la tarea de un
 *    compañero (empujarla a completed con pago al dueño, o degradarla a
 *    awaiting_validation con fotos basura).
 *  - Pago SIN ancla: un match de la IA sobre una asignación ya pagada (completada y
 *    luego desmarcada) depositaba otra vez.
 *
 * Reglas de esta ronda (mismos patrones del módulo):
 *  1. No privilegiado sólo somete evidencia de SUS propias asignaciones (403 si no).
 *  2. El pago del match ancla en coins_awarded (la marca única de pago).
 */
class TaskAiValidateGuardTest extends TestCase
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

    private function makeUser(string $role = 'empleado', ?string $hireDate = null): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'hire_date' => $hireDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function makeAiTask(int $id = 900): Task
    {
        return Task::create([
            'id' => $id,
            'title' => 'Tarea IA',
            'tenant_id' => 1,
            'validation_mode' => 'ai_comparison',
            'ai_comparison_enabled' => true,
            'priority' => 'normal',
            'points' => 10,
        ]);
    }

    private function makeAssignment(Task $task, ?int $userId, array $overrides = []): TaskAssignment
    {
        return TaskAssignment::create(array_merge([
            'id' => 'ai-' . uniqid(),
            'task_id' => $task->id,
            'user_id' => $userId,
            'status' => 'in_progress',
            'tenant_id' => 1,
            'date' => now()->toDateString(),
        ], $overrides));
    }

    private function aiValidate(User $actor, TaskAssignment $assignment)
    {
        return $this->actingAs($actor)->postJson("/api/v1/task-assignments/{$assignment->id}/ai-validate", [
            'evidence_photo_base64' => base64_encode('foto'),
        ]);
    }

    /**
     * Fija la semilla de mt_rand para que la curva de antigüedad (>90 días → 90% IA)
     * caiga DETERMINISTA en la rama de IA en la siguiente llamada del controller.
     */
    /**
     * Semilla cuyas PRIMERAS tiradas caen todas en la rama de IA (<= 50, el umbral más estricto
     * de los dos que usa el controlador).
     *
     * (2026-08-22) Antes se buscaba una semilla cuya PRIMERA tirada bastara. Si algo entre el
     * seed y la comprobación consumía un número al azar —cosa que depende de qué pruebas
     * corrieron antes—, la secuencia se desplazaba y la rama se iba a la humana: la prueba pasaba
     * sola y fallaba en la suite completa. Con varias tiradas buenas seguidas, un desplazamiento
     * de un par de posiciones ya no la voltea.
     */
    private function forceAiBranch(): void
    {
        for ($seed = 1; $seed < 100000; $seed++) {
            mt_srand($seed);
            $sirve = true;
            for ($i = 0; $i < 6; $i++) {
                if (mt_rand(1, 100) > 50) {
                    $sirve = false;
                    break;
                }
            }
            if ($sirve) {
                mt_srand($seed);
                return;
            }
        }
        $this->fail('No se encontró semilla para la rama de IA.');
    }

    public function test_empleado_no_puede_someter_evidencia_de_tarea_ajena(): void
    {
        $empleado = $this->makeUser();
        $companero = $this->makeUser();
        $task = $this->makeAiTask();
        $assignment = $this->makeAssignment($task, $companero->id);

        $this->aiValidate($empleado, $assignment)->assertStatus(403);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'in_progress',
        ]);
        $this->assertSame(0, DB::table('wallet_transactions')->where('user_id', $companero->id)->count());
    }

    public function test_match_de_ia_sobre_ya_pagada_no_deposita_de_nuevo(): void
    {
        // Veterano (>90 días) para poder caer en la rama de IA.
        $empleado = $this->makeUser('empleado', now()->subDays(365)->toDateString());
        $task = $this->makeAiTask(901);
        // Ya cobró en su momento (completada y desmarcada): coins_awarded es la marca.
        $assignment = $this->makeAssignment($task, $empleado->id, [
            'points_awarded' => 10,
            'coins_awarded' => 1.00,
        ]);

        $this->mock(GeminiAIService::class, function ($mock) {
            $mock->shouldReceive('compareTaskEvidence')->andReturn(['match' => true, 'confidence' => 0.99]);
        });

        $this->forceAiBranch();
        $response = $this->aiValidate($empleado, $assignment);

        $response->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'completed',
        ]);
        // Ni una transacción: ya había cobrado.
        $this->assertSame(0, DB::table('wallet_transactions')->where('user_id', $empleado->id)->count());
    }

    public function test_match_de_ia_sobre_propia_no_pagada_si_paga(): void
    {
        $empleado = $this->makeUser('empleado', now()->subDays(365)->toDateString());
        $task = $this->makeAiTask(902);
        $assignment = $this->makeAssignment($task, $empleado->id);

        $this->mock(GeminiAIService::class, function ($mock) {
            $mock->shouldReceive('compareTaskEvidence')->andReturn(['match' => true, 'confidence' => 0.99]);
        });

        $this->forceAiBranch();
        $this->aiValidate($empleado, $assignment)->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'completed',
            'coins_awarded' => 1.00,
        ]);
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $empleado->id)->count());
    }

    public function test_supervisor_si_puede_someter_evidencia_de_su_equipo(): void
    {
        $supervisor = $this->makeUser('supervisor');
        $empleado = $this->makeUser('empleado', now()->subDays(5)->toDateString()); // novato → siempre humano
        $task = $this->makeAiTask(903);
        $assignment = $this->makeAssignment($task, $empleado->id);

        // Novato (<30 días): la curva manda SIEMPRE a revisión humana — no hay pago
        // que verificar, sólo que el privilegiado pasa el gate de ownership.
        $this->aiValidate($supervisor, $assignment)->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'awaiting_validation',
        ]);
    }
}
