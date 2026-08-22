<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sin turno abierto no se trabaja una tarea (prueba del dueño, 2026-08-21).
 *
 * Con el dial en "Acceso Bloqueado" —sin entrada registrada— la pestaña de Tareas seguía
 * dejando poner una tarea en curso y completarla. La pantalla ahora cierra esas pestañas, pero
 * la cerradura es el servidor: un colaborador sin `check_in` abierto hoy no puede mover su
 * tarea a en curso / pausada / completada. Los mandos no se gatean (validan y reasignan tareas
 * ajenas), y deshacer hacia `pending` tampoco.
 */
class TareaSinTurnoAbiertoTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 21;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Turno QA', 'subdomain' => 'turnoqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function usuario(string $rol): User
    {
        $user = User::factory()->create(['role' => $rol]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);

        return $user->fresh();
    }

    /** Una tarea al vuelo del supervisor para el empleado: la forma más corta de tener una asignación real. */
    private function asignacionPara(User $supervisor, User $empleado): string
    {
        $r = $this->actingAs($supervisor)->postJson('/api/v1/task-assignments/al-vuelo', [
            'title' => 'Acomodar el aparador',
            'estimated_mins' => 15,
            'assistant_type' => 'evidencia_foto',
            'assistant_prompt' => 'Foto del aparador terminado.',
            'target_user_id' => $empleado->id,
        ]);
        $r->assertStatus(201);

        return (string) $r->json('assignment_id');
    }

    public function test_sin_entrada_registrada_no_se_puede_poner_la_tarea_en_curso(): void
    {
        $supervisor = $this->usuario('supervisor');
        $empleado = $this->usuario('empleado');
        $id = $this->asignacionPara($supervisor, $empleado);

        $this->actingAs($empleado)
            ->putJson("/api/v1/task-assignments/{$id}", ['status' => 'in_progress'])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => 'Registra tu entrada en el Reloj antes de trabajar una tarea.']);

        $this->assertSame('pending', DB::table('task_assignments')->where('id', $id)->value('status'),
            'la tarea no debe moverse sin turno abierto');

        // Tampoco se puede completar de golpe.
        $this->actingAs($empleado)
            ->putJson("/api/v1/task-assignments/{$id}", ['status' => 'completed'])
            ->assertStatus(422);
    }

    public function test_con_entrada_registrada_si_se_trabaja(): void
    {
        $supervisor = $this->usuario('supervisor');
        $empleado = $this->usuario('empleado');
        $id = $this->asignacionPara($supervisor, $empleado);

        app(ClockService::class)->processPunch($empleado, 'check_in');

        $this->actingAs($empleado)
            ->putJson("/api/v1/task-assignments/{$id}", ['status' => 'in_progress'])
            ->assertOk();

        $this->assertSame('in_progress', DB::table('task_assignments')->where('id', $id)->value('status'));
    }

    public function test_tras_la_salida_el_turno_ya_no_esta_abierto(): void
    {
        $supervisor = $this->usuario('supervisor');
        $empleado = $this->usuario('empleado');
        $id = $this->asignacionPara($supervisor, $empleado);

        $reloj = app(ClockService::class);
        $reloj->processPunch($empleado, 'check_in');
        $reloj->processPunch($empleado, 'check_out');

        $this->actingAs($empleado)
            ->putJson("/api/v1/task-assignments/{$id}", ['status' => 'in_progress'])
            ->assertStatus(422);
    }

    /** Los mandos no se gatean: reasignan y validan tareas de otros sin fichar ellos. */
    public function test_el_supervisor_no_necesita_turno_abierto(): void
    {
        $supervisor = $this->usuario('supervisor');
        $empleado = $this->usuario('empleado');
        $id = $this->asignacionPara($supervisor, $empleado);

        $this->actingAs($supervisor)
            ->putJson("/api/v1/task-assignments/{$id}", ['status' => 'in_progress'])
            ->assertOk();
    }
}
