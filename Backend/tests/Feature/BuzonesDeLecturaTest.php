<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Buzones con lectura (Plan A5, 2026-09-07).
 *
 * Lo que la plantilla manda desde el Reloj —denuncias de compañeros, buzón anónimo y evaluación
 * 360— prometía "enviado de forma segura" y NADIE lo leía: los endpoints de lectura de los dos
 * buzones existían sin pantalla, y los del 360 (`myResults`, `scores`) existían sin ruta y
 * consultaban columnas (`cycle_month`, `leadership_score`) que la tabla no tenía.
 *
 * Aquí se protege el contrato completo: quién puede leer cada buzón, que el anónimo no exponga
 * autor, que el evaluado vea promedios sin evaluadores, que el ranking del ciclo responda, y que
 * ninguna empresa vea lo de otra.
 */
class BuzonesDeLecturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([1 => 'buzonesqa', 2 => 'otraqa'] as $id => $sub) {
            DB::table('tenants')->insertOrIgnore([
                'id' => $id, 'name' => "Empresa {$sub}", 'subdomain' => $sub, 'plan' => 'pro',
                'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function persona(string $rol, int $tenant = 1): User
    {
        $user = User::factory()->create(['role' => $rol]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenant]);

        return $user->refresh();
    }

    // --- Buzón anónimo -----------------------------------------------------------------------

    public function test_el_buzon_anonimo_lo_lee_el_admin_y_no_trae_autor(): void
    {
        $empleado = $this->persona('empleado');
        $admin = $this->persona('admin');

        $this->actingAs($empleado)
            ->postJson('/api/v1/anonymous-feedback', ['type' => 'acoso', 'content' => 'El encargado grita en piso.'])
            ->assertStatus(201);

        $respuesta = $this->actingAs($admin)->getJson('/api/v1/anonymous-feedback')->assertOk();

        $respuesta->assertJsonCount(1)
            ->assertJsonPath('0.type', 'acoso')
            ->assertJsonPath('0.content', 'El encargado grita en piso.');

        // Anónimo de verdad: ni user_id, ni reporter, ni nombre en la respuesta.
        $fila = $respuesta->json('0');
        $this->assertArrayNotHasKey('user_id', $fila);
        $this->assertArrayNotHasKey('reporter_id', $fila);
        $this->assertArrayNotHasKey('name', $fila);
        $this->assertStringNotContainsString($empleado->name, $respuesta->getContent());
    }

    public function test_el_buzon_anonimo_no_lo_lee_el_supervisor_ni_el_empleado(): void
    {
        $this->actingAs($this->persona('empleado'))
            ->postJson('/api/v1/anonymous-feedback', ['type' => 'ambiente', 'content' => 'Queja sobre el supervisor.'])
            ->assertStatus(201);

        $this->actingAs($this->persona('supervisor'))->getJson('/api/v1/anonymous-feedback')->assertStatus(403);
        $this->actingAs($this->persona('empleado'))->getJson('/api/v1/anonymous-feedback')->assertStatus(403);
    }

    // --- Denuncias de compañeros --------------------------------------------------------------

    public function test_las_denuncias_las_leen_admin_y_supervisor_con_quien_reporta_y_a_quien(): void
    {
        $reporta = $this->persona('empleado');
        $acusado = $this->persona('empleado');
        $supervisor = $this->persona('supervisor');

        $this->actingAs($reporta)
            ->postJson('/api/v1/reports/employee', ['accused_id' => $acusado->id, 'type' => 'abandono', 'details' => 'Dejó la caja sola.'])
            ->assertStatus(201);

        $this->actingAs($supervisor)->getJson('/api/v1/reports/employee')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.type', 'abandono')
            ->assertJsonPath('0.reporter.name', $reporta->name)
            ->assertJsonPath('0.accused.name', $acusado->name);

        $this->actingAs($this->persona('admin'))->getJson('/api/v1/reports/employee')->assertOk()->assertJsonCount(1);
        $this->actingAs($reporta)->getJson('/api/v1/reports/employee')->assertStatus(403);
    }

    // --- Evaluación 360 -----------------------------------------------------------------------

    private function evaluar(User $evaluador, User $evaluado, int $equipo, int $actitud, int $desempeno, ?string $comentario = null): void
    {
        $this->actingAs($evaluador)->postJson('/api/v1/clock/evaluations', [
            'evaluated_user_id' => $evaluado->id,
            'teamwork_score' => $equipo,
            'attitude_score' => $actitud,
            'performance_score' => $desempeno,
            'comments' => $comentario,
        ])->assertStatus(201);
    }

    public function test_la_evaluacion_guarda_ciclo_y_liderazgo_y_el_evaluado_ve_promedios_sin_evaluadores(): void
    {
        $evaluada = $this->persona('empleado');
        $colega1 = $this->persona('empleado');
        $colega2 = $this->persona('empleado');

        $this->evaluar($colega1, $evaluada, 5, 3, 4, 'Muy buena compañera.');
        $this->evaluar($colega2, $evaluada, 3, 5, 2);

        // Las dos columnas que antes se tiraban en silencio ahora quedan escritas.
        $this->assertDatabaseHas('performance_evaluations', [
            'evaluated_user_id' => $evaluada->id,
            'evaluator_user_id' => $colega1->id,
            'cycle_month' => now()->format('Y-m'),
            'leadership_score' => 4, // cae al desempeño cuando no viene
        ]);

        $respuesta = $this->actingAs($evaluada)->getJson('/api/v1/clock/evaluations/my-results')->assertOk();

        $respuesta->assertJsonPath('evaluations_count', 2)
            ->assertJsonPath('averages.teamwork', 4)
            ->assertJsonPath('averages.attitude', 4)
            ->assertJsonPath('averages.performance', 3)
            ->assertJsonPath('averages.leadership', 3)
            ->assertJsonPath('anonymous_comments.0', 'Muy buena compañera.');

        // Confidencial: los nombres de quienes evaluaron no viajan.
        $this->assertStringNotContainsString($colega1->name, $respuesta->getContent());
        $this->assertStringNotContainsString($colega2->name, $respuesta->getContent());
        $this->assertStringNotContainsString('evaluator_user_id', $respuesta->getContent());
    }

    public function test_el_ranking_del_ciclo_responde_a_admin_y_supervisor_y_no_a_empleados(): void
    {
        $evaluada = $this->persona('empleado');
        $colega = $this->persona('empleado');
        $this->evaluar($colega, $evaluada, 4, 4, 4);

        $ranking = $this->actingAs($this->persona('supervisor'))
            ->getJson('/api/v1/clock/evaluations/scores')
            ->assertOk()
            ->assertJsonPath('cycle_month', now()->format('Y-m'))
            ->assertJsonCount(1, 'scores');

        $this->assertEquals($evaluada->id, $ranking->json('scores.0.user_id'));
        $this->assertEquals(1, $ranking->json('scores.0.evaluations_received'));
        $this->assertEquals(4, (float) $ranking->json('scores.0.overall_score'));

        $this->actingAs($this->persona('admin'))->getJson('/api/v1/clock/evaluations/scores')->assertOk();
        $this->actingAs($colega)->getJson('/api/v1/clock/evaluations/scores')->assertStatus(403);
    }

    public function test_un_ciclo_mal_formado_cae_al_mes_en_curso_en_vez_de_reventar(): void
    {
        $this->actingAs($this->persona('admin'))
            ->getJson('/api/v1/clock/evaluations/scores?month=2026-13')
            ->assertOk()
            ->assertJsonPath('cycle_month', now()->format('Y-m'));

        $this->actingAs($this->persona('empleado'))
            ->getJson("/api/v1/clock/evaluations/my-results?month=' OR 1=1 --")
            ->assertOk()
            ->assertJsonPath('cycle_month', now()->format('Y-m'))
            ->assertJsonPath('evaluations_count', 0);
    }

    // --- Aislamiento por empresa --------------------------------------------------------------

    public function test_ninguna_empresa_ve_los_buzones_de_otra(): void
    {
        $empleado1 = $this->persona('empleado', 1);
        $acusado1 = $this->persona('empleado', 1);
        $this->actingAs($empleado1)
            ->postJson('/api/v1/anonymous-feedback', ['type' => 'sugerencia', 'content' => 'Sólo para la empresa 1.'])
            ->assertStatus(201);
        $this->actingAs($empleado1)
            ->postJson('/api/v1/reports/employee', ['accused_id' => $acusado1->id, 'type' => 'conducta', 'details' => 'Sólo empresa 1.'])
            ->assertStatus(201);
        $this->evaluar($empleado1, $acusado1, 5, 5, 5);

        $adminOtra = $this->persona('admin', 2);
        $this->actingAs($adminOtra)->getJson('/api/v1/anonymous-feedback')->assertOk()->assertJsonCount(0);
        $this->actingAs($adminOtra)->getJson('/api/v1/reports/employee')->assertOk()->assertJsonCount(0);
        $this->actingAs($adminOtra)->getJson('/api/v1/clock/evaluations/scores')->assertOk()->assertJsonCount(0, 'scores');
    }
}
