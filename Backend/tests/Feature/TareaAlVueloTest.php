<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §31 — Tarea al vuelo (decisión de producto P5-P7, 2026-08-03).
 *
 * Textual del jefe: solo supervisor/admin (P6, "el permiso es el candado anti-fraude"), nunca
 * para uno mismo, y paga con las MISMAS reglas que una rutina (P7): minutos obligatorios,
 * evidencia elegida al crearla, firma del supervisor al validar.
 */
class TareaAlVueloTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 19;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'AlVuelo QA', 'subdomain' => 'vueloqa',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function usuario(string $rol, int $tenant = null): User
    {
        $user = User::factory()->create(['role' => $rol]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenant ?? $this->tenantId]);

        return $user->fresh();
    }

    private function lanzar(User $quien, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($quien)->postJson('/api/v1/task-assignments/al-vuelo', array_merge([
            'title' => 'Revisar la gotera del baño',
            'estimated_mins' => 20,
            'assistant_type' => 'evidencia_foto',
            'assistant_prompt' => 'Foto de la reparación terminada.',
        ], $extra));
    }

    public function test_un_supervisor_lanza_una_tarea_y_queda_formal(): void
    {
        $supervisor = $this->usuario('supervisor');
        $empleado = $this->usuario('empleado');

        $r = $this->lanzar($supervisor, ['target_user_id' => $empleado->id]);
        $r->assertStatus(201)->assertJson(['success' => true]);

        $task = DB::table('tasks')->where('id', $r->json('task_id'))->first();
        $this->assertSame(20, (int) $task->estimated_mins, 'Los minutos viajan tal cual (costeo).');
        $this->assertSame('forced', $task->validation_mode,
            'P7: igual de formal que una rutina — exige firma del supervisor.');
        $this->assertSame('evidencia_foto', $task->assistant_type,
            'La evidencia se elige AL CREARLA, no tras el incumplimiento.');

        $assignment = DB::table('task_assignments')->where('id', $r->json('assignment_id'))->first();
        $this->assertSame($empleado->id, (int) $assignment->user_id);
        $this->assertSame('pending', $assignment->status);
        $this->assertSame('extra', $assignment->origin);
        $this->assertStringStartsWith('fly_', $assignment->id);
    }

    public function test_un_empleado_no_puede_lanzar_tareas(): void
    {
        // P6: el permiso ES el candado.
        $empleado = $this->usuario('empleado');
        $otro = $this->usuario('empleado');

        $this->lanzar($empleado, ['target_user_id' => $otro->id])->assertStatus(403);

        $this->assertSame(0, DB::table('tasks')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_nadie_se_lanza_una_tarea_a_si_mismo(): void
    {
        // La máquina de auto-pago: crear la tarea, completarla, cobrarla. Ni el admin.
        $admin = $this->usuario('admin');

        $r = $this->lanzar($admin, ['target_user_id' => $admin->id]);

        $r->assertStatus(422);
        $this->assertStringContainsString('a ti mismo', $r->json('message'));
        $this->assertSame(0, DB::table('tasks')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_sin_minutos_estimados_no_hay_tarea(): void
    {
        // El mismo guardarraíl del catálogo, en esta puerta: sin minutos el costo sale mal
        // en silencio.
        $supervisor = $this->usuario('supervisor');
        $empleado = $this->usuario('empleado');

        $this->actingAs($supervisor)->postJson('/api/v1/task-assignments/al-vuelo', [
            'title' => 'Sin minutos', 'target_user_id' => $empleado->id,
        ])->assertStatus(422);
    }

    public function test_no_se_lanza_a_colaboradores_de_otra_empresa(): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => 23, 'name' => 'Ajena', 'subdomain' => 'ajena23', 'plan' => 'basic',
            'max_users' => 5, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $supervisor = $this->usuario('supervisor');
        $ajeno = $this->usuario('empleado', 23);

        $this->lanzar($supervisor, ['target_user_id' => $ajeno->id])->assertStatus(404);
    }

    public function test_repetirla_el_mismo_dia_no_duplica_la_asignacion(): void
    {
        $supervisor = $this->usuario('supervisor');
        $empleado = $this->usuario('empleado');

        $r1 = $this->lanzar($supervisor, ['target_user_id' => $empleado->id]);
        // Mismo título/destino: crea OTRA task (dos goteras distintas son legítimas), pero si
        // el id determinista coincidiera (misma task), insertOrIgnore no duplicaría. Aquí lo
        // que se fija es que cada lanzamiento produce SU asignación única y rastreable.
        $r2 = $this->lanzar($supervisor, ['target_user_id' => $empleado->id]);

        $this->assertNotSame($r1->json('assignment_id'), $r2->json('assignment_id'));
        $this->assertSame(2,
            DB::table('task_assignments')->where('tenant_id', $this->tenantId)->count());
    }

    public function test_la_validacion_forzada_exige_firma_al_completar(): void
    {
        // El círculo completo de P7: el empleado la completa → cae en awaiting_validation
        // (no se paga sola); la firma viaja por las puertas ya endurecidas.
        $supervisor = $this->usuario('supervisor');
        $empleado = $this->usuario('empleado');

        // La política de validación exige ORGANIGRAMA (H26/H27): sin jerarquía no hay quién
        // firme y la validación no se activa. En producción la deja el wizard; aquí se siembra.
        $jefeRoleId = DB::table('job_roles')->insertGetId([
            'tenant_id' => $this->tenantId, 'name' => 'Jefe', 'area' => 'Gerencia', 'jerarquiaLlaves' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pisoRoleId = DB::table('job_roles')->insertGetId([
            'tenant_id' => $this->tenantId, 'name' => 'Piso', 'area' => 'Operaciones', 'jerarquiaLlaves' => 3,
            'reports_to_role_id' => $jefeRoleId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employees')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $empleado->id, 'name' => $empleado->name,
            'email' => $empleado->email, 'job_role_id' => $pisoRoleId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->lanzar($supervisor, ['target_user_id' => $empleado->id]);
        $assignmentId = $r->json('assignment_id');

        $this->actingAs($empleado)->putJson("/api/v1/task-assignments/{$assignmentId}", [
            'status' => 'completed',
        ])->assertStatus(200);

        $this->assertSame('awaiting_validation',
            DB::table('task_assignments')->where('id', $assignmentId)->value('status'),
            'Sin firma del supervisor no hay pago: la tarea al vuelo no es dinero gratis.');
        $coins = DB::table('task_assignments')->where('id', $assignmentId)->value('coins_awarded');
        $this->assertTrue($coins === null || (float) $coins === 0.0,
            'El ancla anti-doble-pago sigue virgen (0/null = sin pago) hasta la firma.');
    }
}
