<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Academia AC3 (auditoría 2026-08-04): el examen se calificaba en el navegador.
 *
 * `submitQuiz` comparaba las respuestas en el cliente y, si le salía que aprobaba, posteaba
 * `{status:'completed', score:100}`. El score era un literal, las respuestas del alumno no
 * viajaban nunca y las correctas SÍ bajaban al navegador dentro de `quiz_data`. Completar un
 * curso era apretar un botón — y como el bloqueo del checador por 3 retardos se levanta con el
 * `completed_at` del curso de puntualidad, el colaborador se desbloqueaba el reloj solo.
 *
 * Aquí se fija: las respuestas correctas no salen del servidor, la calificación la hace el
 * servidor, y declararse aprobado por la puerta vieja ya no funciona en cursos con examen.
 */
class AcademiaExamenServidorTest extends TestCase
{
    use RefreshDatabase;

    private const QUIZ = [
        [
            'question' => '¿Cuál es la tolerancia?',
            'options' => ['10 minutos', 'No hay', '30 minutos'],
            'correctAnswer' => 0,
        ],
        [
            'question' => '¿Qué pasa con 3 retardos?',
            'options' => ['Nada', 'Bloqueo del checador', 'Despido'],
            'answer' => 'Bloqueo del checador',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'free',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function usuario(string $role = 'empleado'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        return $user->refresh();
    }

    private function curso(array $quiz = self::QUIZ, string $tipo = 'training'): AcademyCourse
    {
        return AcademyCourse::create([
            'title' => 'Curso de Puntualidad y Compromiso Laboral',
            'description' => 'Con evaluación.',
            'course_type' => $tipo,
            'quiz_data' => $quiz,
            'is_active' => true,
            'tenant_id' => 1,
        ]);
    }

    /**
     * El tenant 1 ya trae cursos sembrados por migración (`seed_lft_course`), así que la
     * respuesta trae más de uno: se busca el del caso por id en vez de asumir la posición.
     */
    private function cursoEnRespuesta($respuesta, AcademyCourse $curso): array
    {
        foreach ($respuesta->json('courses') as $fila) {
            if ((int) $fila['id'] === (int) $curso->id) {
                return $fila;
            }
        }

        $this->fail('el curso del caso no vino en la lista');
    }

    public function test_al_colaborador_no_se_le_mandan_las_respuestas_correctas(): void
    {
        $curso = $this->curso();

        $lista = $this->actingAs($this->usuario())->getJson('/api/v1/academy/courses');
        $lista->assertStatus(200);

        $quiz = $this->cursoEnRespuesta($lista, $curso)['quiz_data'];
        $this->assertCount(2, $quiz, 'las preguntas y opciones sí se mandan');
        $this->assertArrayNotHasKey('correctAnswer', $quiz[0]);
        $this->assertArrayNotHasKey('answer', $quiz[1]);
        $this->assertSame('¿Cuál es la tolerancia?', $quiz[0]['question']);
        $this->assertCount(3, $quiz[0]['options']);

        // El detalle del curso tampoco las filtra.
        $detalle = $this->actingAs($this->usuario())->getJson("/api/v1/academy/courses/{$curso->id}");
        $this->assertArrayNotHasKey('correctAnswer', $detalle->json('quiz_data.0'));
    }

    public function test_quien_administra_los_cursos_si_ve_las_respuestas(): void
    {
        $curso = $this->curso();

        $lista = $this->actingAs($this->usuario('admin'))->getJson('/api/v1/academy/courses');

        // Sin esto el gestor no podría editar el examen que él mismo configura.
        $quiz = $this->cursoEnRespuesta($lista, $curso)['quiz_data'];
        $this->assertSame(0, $quiz[0]['correctAnswer']);
        $this->assertSame('Bloqueo del checador', $quiz[1]['answer']);
    }

    public function test_el_examen_reprobado_no_completa_el_curso_y_cuenta_el_intento(): void
    {
        $user = $this->usuario();
        $curso = $this->curso();

        $respuesta = $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
            'answers' => [2, 0],
        ]);

        $respuesta->assertStatus(200)
            ->assertJson(['passed' => false, 'score' => 0, 'failed_attempts' => 1]);

        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $user->id,
            'course_id' => $curso->id,
            'status' => 'failed',
            'failed_attempts' => 1,
        ]);

        // Segundo intento fallido: el conteo sobrevive (antes vivía en el navegador y se
        // reiniciaba con solo cerrar y reabrir el curso).
        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
            'answers' => [1, 1],
        ])->assertJson(['passed' => false, 'failed_attempts' => 2]);
    }

    public function test_el_examen_aprobado_completa_el_curso_con_el_score_real(): void
    {
        $user = $this->usuario();
        $curso = $this->curso(self::QUIZ, 'induction');

        // La segunda pregunta declara su respuesta por TEXTO: el servidor la resuelve igual.
        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
            'answers' => [0, 1],
        ])->assertStatus(200)->assertJson([
            'passed' => true,
            'score' => 100,
            'correct_count' => 2,
        ]);

        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $user->id,
            'course_id' => $curso->id,
            'status' => 'completed',
            'score' => 100,
        ]);

        $this->assertTrue((bool) $user->fresh()->has_completed_induction, 'la inducción aprobada levanta su bandera');
    }

    public function test_no_se_puede_declarar_completado_un_curso_con_examen(): void
    {
        $user = $this->usuario();
        $curso = $this->curso();

        // La puerta vieja, que es exactamente lo que hacía el frontend.
        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/progress", [
            'status' => 'completed',
            'score' => 100,
        ])->assertStatus(422);

        $this->actingAs($user)->postJson('/api/v1/academy/progress', [
            'course_id' => $curso->id,
            'status' => 'completed',
            'score' => 100,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('user_course_progress', [
            'user_id' => $user->id,
            'course_id' => $curso->id,
            'status' => 'completed',
        ]);
    }

    public function test_un_curso_sin_examen_se_sigue_completando_al_verlo(): void
    {
        $user = $this->usuario();
        // §38: las lecciones que el módulo de Tareas engancha a una tarea son video sin examen.
        $curso = $this->curso([], 'training');

        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/progress", [
            'status' => 'completed',
        ])->assertStatus(200);

        $this->assertDatabaseHas('user_course_progress', [
            'user_id' => $user->id,
            'course_id' => $curso->id,
            'status' => 'completed',
        ]);
    }

    public function test_el_checador_bloqueado_no_se_desbloquea_declarandose_aprobado(): void
    {
        $user = $this->usuario();
        $curso = $this->curso();

        DB::table('system_settings')->updateOrInsert(
            ['key' => 'punctuality_course_id', 'tenant_id' => 1],
            ['value' => json_encode($curso->id), 'updated_at' => now(), 'created_at' => now()]
        );

        // Tres retardos reales: el dial queda en estado #1 (Fichaje Bloqueado).
        foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $fecha) {
            DB::table('time_entries')->insert([
                'tenant_id' => 1,
                'user_id' => $user->id,
                'type' => 'check_in',
                'date' => $fecha,
                'time' => '09:20:00',
                'is_late' => true,
                'late_minutes' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($user)->getJson('/api/v1/me/punctuality-status')
            ->assertJson(['blocked' => true, 'lates_count' => 3]);

        // Declararse aprobado ya no pasa...
        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/progress", [
            'status' => 'completed',
        ])->assertStatus(422);

        // ...y reprobar el examen tampoco desbloquea.
        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
            'answers' => [2, 2],
        ])->assertJson(['passed' => false]);

        $this->actingAs($user)->getJson('/api/v1/me/punctuality-status')
            ->assertJson(['blocked' => true]);

        // Aprobándolo de verdad, sí.
        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
            'answers' => [0, 1],
        ])->assertJson(['passed' => true]);

        $this->actingAs($user)->getJson('/api/v1/me/punctuality-status')
            ->assertJson(['blocked' => false, 'course_completed' => true]);
    }
}
