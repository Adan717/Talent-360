<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A3 (auditoría 2026-07-27): el pago al completar vía POST /sync/tasks anclaba en el
 * STATUS (`$existing->status !== 'completed'`), no en `coins_awarded`. El PUT permite
 * legítimamente DESMARCAR una completada (checklist del Reloj) conservando el pago ya
 * hecho; re-completarla por sync volvía a depositar. Ciclo:
 *   sync-completa (paga) → PUT-desmarca → sync-completa (¡pagaba otra vez!) → ...
 *
 * Regla de esta ronda: `coins_awarded` es la ÚNICA marca del pago en las 6 puertas de
 * depósito (sync, update, validate, validate-with-pin, ai-validate, resolve-incomplete).
 * El guard de status se conserva ADEMÁS del ancla: una fila legacy completada sin
 * coins_awarded (pre-gamificación) tampoco debe cobrar al re-emitirse en el full-state.
 */
class SyncTasksPaymentAnchorTest extends TestCase
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

    private function makeTask(int $id = 800): Task
    {
        return Task::create([
            'id' => $id,
            'title' => 'Tarea A3',
            'tenant_id' => 1,
            'validation_mode' => 'auto', // sin validación: completar paga directo
            'priority' => 'normal',
            'points' => 10,
        ]);
    }

    private function syncComplete(User $actor, string $assignmentId, int $taskId)
    {
        return $this->actingAs($actor)->postJson('/api/v1/sync/tasks', [
            'assignments' => [[
                'id' => $assignmentId,
                'task_id' => $taskId,
                'user_id' => $actor->id,
                'status' => 'completed',
            ]],
        ]);
    }

    public function test_ciclo_completar_desmarcar_recompletar_paga_una_sola_vez(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask();

        // 1. Completa vía sync → paga (1 transacción).
        $this->syncComplete($empleado, 'ancla-1', $task->id)->assertStatus(200);
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $empleado->id)->count());
        $this->assertDatabaseHas('task_assignments', ['id' => 'ancla-1', 'status' => 'completed']);

        // 2. Desmarca vía PUT (flujo legítimo del checklist del Reloj) → sigue pagada.
        $this->actingAs($empleado)->putJson('/api/v1/task-assignments/ancla-1', [
            'status' => 'pending',
        ])->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => 'ancla-1', 'status' => 'pending']);

        // 3. Re-completa vía sync → NO vuelve a pagar (el ancla coins_awarded manda).
        $this->syncComplete($empleado, 'ancla-1', $task->id)->assertStatus(200);
        $this->assertDatabaseHas('task_assignments', ['id' => 'ancla-1', 'status' => 'completed']);
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $empleado->id)->count());

        $wallet = DB::table('user_wallets')->where('user_id', $empleado->id)->first();
        $this->assertEquals(1.0, (float) $wallet->balance_coins);
    }

    public function test_fila_legacy_completada_sin_coins_no_cobra_al_reemitirse(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask(801);

        // Fila pre-gamificación: completada de origen, sin marca de pago.
        TaskAssignment::create([
            'id' => 'legacy-1',
            'task_id' => $task->id,
            'user_id' => $empleado->id,
            'status' => 'completed',
            'tenant_id' => 1,
            'date' => now()->toDateString(),
        ]);

        // El sync full-state re-emite la fila tal cual (sticky completed).
        $this->syncComplete($empleado, 'legacy-1', $task->id)->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', ['id' => 'legacy-1', 'status' => 'completed']);
        // El guard de status sigue vigente: re-emitir una completada no genera pago.
        $this->assertSame(0, DB::table('wallet_transactions')->where('user_id', $empleado->id)->count());
    }

    public function test_pagada_conserva_sus_montos_originales_al_resincronizar(): void
    {
        $empleado = $this->makeUser();
        $task = $this->makeTask(802);

        $this->syncComplete($empleado, 'ancla-2', $task->id)->assertStatus(200);

        // El admin sube los puntos de la tarea DESPUÉS del pago.
        $task->update(['points' => 100]);

        // Desmarcar y re-completar no re-escribe lo pagado con los puntos nuevos
        // (el registro debe seguir cuadrando contra el monedero).
        $this->actingAs($empleado)->putJson('/api/v1/task-assignments/ancla-2', [
            'status' => 'pending',
        ])->assertStatus(200);
        $this->syncComplete($empleado, 'ancla-2', $task->id)->assertStatus(200);

        $this->assertDatabaseHas('task_assignments', [
            'id' => 'ancla-2',
            'status' => 'completed',
            'points_awarded' => 10,
            'coins_awarded' => 1.00,
        ]);
        $this->assertSame(1, DB::table('wallet_transactions')->where('user_id', $empleado->id)->count());
    }
}
