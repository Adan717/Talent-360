<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Anti-doble-pago de la gamificación de Tareas (bug de dinero cazado en la auditoría
 * pre-producción).
 *
 * El flujo COTIDIANO es: el colaborador marca la tarea como completada (y `update` le deposita su
 * recompensa) y DESPUÉS un supervisor la valida desde su panel. Antes, `validateAssignment`
 * depositaba de nuevo el mismo monto: dos transacciones con el mismo `reference_id`, monedas y XP
 * que nadie ganó, y un monedero inflado de forma silenciosa.
 *
 * La regla es: una asignación paga UNA sola vez. `coins_awarded` es la marca del pago (mismo ancla
 * en las dos puertas). El flujo con validación OBLIGATORIA no se ve afectado: ahí la asignación
 * llega a `awaiting_validation` sin haber cobrado, y el pago del validador es el único que ocurre.
 */
class TaskNoDoblePagoTest extends TestCase
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

    private function seedAssignment(string $id, int $userId, int $taskId, string $status, $coins = 0): void
    {
        TaskAssignment::create([
            'id' => $id,
            'task_id' => $taskId,
            'user_id' => $userId,
            'status' => $status,
            'date' => now()->format('Y-m-d'),
            'coins_awarded' => $coins,
            'tenant_id' => 1,
        ]);
    }

    private function coinsDepositadas(int $userId): float
    {
        return (float) DB::table('wallet_transactions')->where('user_id', $userId)->sum('amount');
    }

    public function test_validar_una_tarea_ya_pagada_no_vuelve_a_pagar(): void
    {
        $admin = $this->makeUser('admin');
        $empleado = $this->makeUser();
        $task = Task::create(['id' => 4100, 'title' => 'Corte de caja', 'tenant_id' => 1, 'points' => 30]);

        // El colaborador ya completó la tarea y cobró (3.00 monedas = 30 pts * 0.10).
        $this->seedAssignment('pago-1', $empleado->id, $task->id, 'completed', 3.00);
        DB::table('wallet_transactions')->insert([
            'tenant_id' => 1, 'user_id' => $empleado->id, 'type' => 'earned_task',
            'amount' => 3.00, 'xp_amount' => 30, 'reference_type' => 'TaskAssignment',
            'reference_id' => 'pago-1', 'description' => 'Recompensa por completar tarea',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/assignments/pago-1/validate', ['status' => 'completed', 'score_percentage' => 100])
            ->assertStatus(200);

        // Sigue habiendo UN solo pago: el supervisor validó, no volvió a pagar.
        $this->assertSame(3.00, $this->coinsDepositadas($empleado->id));
        $this->assertSame(1, DB::table('wallet_transactions')->where('reference_id', 'pago-1')->count());
    }

    public function test_validar_una_tarea_pendiente_de_validacion_si_paga(): void
    {
        $admin = $this->makeUser('admin');
        $empleado = $this->makeUser();
        $task = Task::create(['id' => 4200, 'title' => 'Requiere supervisor', 'tenant_id' => 1, 'points' => 40]);

        // Flujo con validación OBLIGATORIA: llega a manos del supervisor SIN haber cobrado.
        // coins_awarded = 0 es la marca de "no ha cobrado" (la columna es NOT NULL default 0).
        $this->seedAssignment('pago-2', $empleado->id, $task->id, 'awaiting_validation', 0);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/assignments/pago-2/validate', ['status' => 'completed', 'score_percentage' => 100])
            ->assertStatus(200);

        // Este pago es el único y sí debe ocurrir (40 pts * 0.10 = 4.00).
        $this->assertSame(4.00, $this->coinsDepositadas($empleado->id));
        $this->assertDatabaseHas('task_assignments', ['id' => 'pago-2', 'status' => 'completed']);
    }

    public function test_rechazar_una_tarea_no_paga(): void
    {
        $admin = $this->makeUser('admin');
        $empleado = $this->makeUser();
        $task = Task::create(['id' => 4300, 'title' => 'Rechazada', 'tenant_id' => 1, 'points' => 50]);

        $this->seedAssignment('pago-3', $empleado->id, $task->id, 'awaiting_validation', 0);

        $this->actingAs($admin)
            ->postJson('/api/v1/admin/assignments/pago-3/validate', [
                'status' => 'in_progress',
                'feedback' => 'Rehacer: faltó firmar el acta.',
            ])
            ->assertStatus(200);

        $this->assertSame(0.0, $this->coinsDepositadas($empleado->id));
    }
}
