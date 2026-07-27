<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * C1 (auditoría 2026-07-27): POST /task-assignments/{id}/resolve-incomplete son "los 3
 * botones del gerente" para tareas inconclusas, pero el endpoint estaba en el grupo
 * autenticado general SIN gate de rol, SIN exigir flagged_incomplete y SIN ancla
 * anti-doble-pago:
 *  - cualquier empleado se auto-aprobaba (y auto-PAGABA) cualquier tarea propia,
 *    saltándose la validación jerárquica completa;
 *  - action=reject servía para sabotear tareas de compañeros;
 *  - approve repetido (o sobre una ya pagada) depositaba otra vez.
 *
 * Reglas que fija esta ronda:
 *  1. Sólo admin/supervisor/platform_admin pueden resolver (mismo isPrivileged del módulo).
 *  2. Nadie resuelve SU PROPIA asignación (mismo anti-auto-validación que /validate).
 *  3. Sólo asignaciones con flagged_incomplete=true son resolubles (422 si no).
 *  4. El pago de approve ancla en coins_awarded (misma marca que update/validate):
 *     una ya pagada no vuelve a depositar.
 */
class ResolveIncompleteGateTest extends TestCase
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

    private function makeUser(string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        return $user->fresh();
    }

    private function makeTask(int $id = 700): Task
    {
        return Task::create([
            'id' => $id,
            'title' => 'Tarea C1',
            'tenant_id' => 1,
            'validation_mode' => 'forced',
            'priority' => 'normal',
            'points' => 20,
        ]);
    }

    private function makeAssignment(Task $task, ?int $userId, array $overrides = []): TaskAssignment
    {
        return TaskAssignment::create(array_merge([
            'id' => 'inc-' . uniqid(),
            'task_id' => $task->id,
            'user_id' => $userId,
            'status' => 'awaiting_validation',
            'flagged_incomplete' => true,
            'date' => now()->subDay()->toDateString(),
            'tenant_id' => 1,
        ], $overrides));
    }

    private function resolve(User $actor, TaskAssignment $assignment, string $action, ?string $feedback = null)
    {
        return $this->actingAs($actor)->postJson("/api/v1/task-assignments/{$assignment->id}/resolve-incomplete", array_filter([
            'action' => $action,
            'feedback' => $feedback,
        ]));
    }

    public function test_empleado_no_puede_auto_aprobarse_ni_cobrar(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask();
        $assignment = $this->makeAssignment($task, $empleado->id);

        $this->resolve($empleado, $assignment, 'approve')->assertStatus(403);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'awaiting_validation',
        ]);
        // Ni un centavo depositado.
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $empleado->id]);
    }

    public function test_empleado_no_puede_rechazar_tarea_de_companero(): void
    {
        $empleado = $this->makeUser();
        $companero = $this->makeUser();
        $task = $this->makeTask(701);
        $assignment = $this->makeAssignment($task, $companero->id);

        $this->resolve($empleado, $assignment, 'reject')->assertStatus(403);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'awaiting_validation',
        ]);
    }

    public function test_admin_no_puede_resolver_su_propia_asignacion(): void
    {
        $admin = $this->makeUser('admin');
        $task = $this->makeTask(702);
        $assignment = $this->makeAssignment($task, $admin->id);

        $this->resolve($admin, $assignment, 'approve')->assertStatus(403);

        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $admin->id]);
    }

    public function test_solo_flagged_incomplete_es_resoluble(): void
    {
        $admin = $this->makeUser('admin');
        $empleado = $this->makeUser();
        $task = $this->makeTask(703);
        // Asignación normal del día, NO flaggeada por el proceso nocturno.
        $assignment = $this->makeAssignment($task, $empleado->id, [
            'status' => 'pending',
            'flagged_incomplete' => false,
        ]);

        $this->resolve($admin, $assignment, 'approve')->assertStatus(422);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $empleado->id]);
    }

    public function test_admin_aprueba_flaggeada_y_paga_una_sola_vez(): void
    {
        $admin = $this->makeUser('admin');
        $empleado = $this->makeUser();
        $task = $this->makeTask(704);
        $assignment = $this->makeAssignment($task, $empleado->id);

        $this->resolve($admin, $assignment, 'approve', 'Trabajo verificado')->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'completed',
            'flagged_incomplete' => false,
            'validated_by' => $admin->id,
        ]);
        // 20 pts → 2.00 monedas, una sola transacción.
        $this->assertDatabaseHas('wallet_transactions', [
            'user_id' => $empleado->id,
            'amount' => 2.00,
        ]);
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $empleado->id)->count());
    }

    public function test_approve_sobre_ya_pagada_no_deposita_de_nuevo(): void
    {
        $admin = $this->makeUser('admin');
        $empleado = $this->makeUser();
        $task = $this->makeTask(705);
        // Ya cobró en su momento (coins_awarded es la marca del pago) y quedó
        // flaggeada después (p. ej. desmarcada y abandonada a media edición).
        $assignment = $this->makeAssignment($task, $empleado->id, [
            'status' => 'awaiting_validation',
            'coins_awarded' => 2.00,
            'points_awarded' => 20,
        ]);

        $this->resolve($admin, $assignment, 'approve')->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => $assignment->id,
            'status' => 'completed',
        ]);
        // Cero transacciones nuevas: el ancla coins_awarded manda.
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $empleado->id]);
    }

    public function test_supervisor_puede_reprogramar_y_rechazar(): void
    {
        $supervisor = $this->makeUser('supervisor');
        $empleado = $this->makeUser();
        $task = $this->makeTask(706);

        $paraReprogramar = $this->makeAssignment($task, $empleado->id);
        $this->resolve($supervisor, $paraReprogramar, 'reschedule')->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => $paraReprogramar->id,
            'status' => 'pending',
            'flagged_incomplete' => false,
            'origin' => 'carried_over',
        ]);

        $paraRechazar = $this->makeAssignment($task, $empleado->id);
        $this->resolve($supervisor, $paraRechazar, 'reject', 'No se hizo')->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', [
            'id' => $paraRechazar->id,
            'status' => 'omitted',
            'flagged_incomplete' => false,
        ]);
        // Reprogramar/rechazar jamás pagan.
        $this->assertDatabaseMissing('wallet_transactions', ['user_id' => $empleado->id]);
    }
}
