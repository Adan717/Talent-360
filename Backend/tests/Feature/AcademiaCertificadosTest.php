<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Academia — el certificado era decorativo (familia H23).
 *
 * "Mis Certificados" imprimía un `div` con `window.print()`: sin folio, sin registro en la base
 * y con las fechas escritas a mano en el código ("del 01 al 15 de Agosto, 2026", iguales para
 * todos). Cualquiera podía imprimir uno editando el HTML y la empresa no tenía cómo distinguirlo
 * de uno real. Encima, la lista sólo mostraba cursos con `certificate_template_id`, un campo que
 * ningún flujo asigna: en la práctica **nadie tenía certificados**.
 *
 * Ahora aprobar emite un registro con folio verificable, y hay una consulta pública por folio.
 */
class AcademiaCertificadosTest extends TestCase
{
    use RefreshDatabase;

    private const QUIZ = [
        [
            'question' => '¿Cuál es la tolerancia?',
            'options' => ['10 minutos', 'No hay', '30 minutos'],
            'correctAnswer' => 0,
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->where('id', 1)->update(['name' => 'Panadería La Espiga']);
    }

    private function usuario(string $nombre = 'Ana Ruiz'): User
    {
        $user = User::factory()->create(['role' => 'empleado', 'name' => $nombre]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        return $user->fresh();
    }

    private function curso(array $quiz = self::QUIZ, string $titulo = 'Manejo Higiénico de Alimentos'): AcademyCourse
    {
        return AcademyCourse::create([
            'title' => $titulo,
            'description' => 'Curso del giro.',
            'course_type' => 'training',
            'quiz_data' => $quiz,
            // (Fase 2, 2026-08-24) Desde el apagón de folios sólo se expide certificado
            // verificable sobre un examen que la EMPRESA configuró. Este curso representa uno
            // configurado, que es lo que se prueba aquí. El caso contrario —el examen de relleno
            // del catálogo, que ya no expide folio— vive en
            // AcademiaFoliosYCandadosTest::test_el_examen_de_relleno_no_expide_folio_verificable.
            'quiz_approved_at' => now(),
            'is_active' => true,
            'tenant_id' => 1,
        ]);
    }

    public function test_aprobar_el_examen_emite_un_certificado_con_folio(): void
    {
        $user = $this->usuario();
        $curso = $this->curso();

        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
            'answers' => [0],
        ])->assertJson(['passed' => true]);

        $certificado = DB::table('course_certificates')->where('user_id', $user->id)->first();

        $this->assertNotNull($certificado, 'aprobar tiene que dejar registro, no sólo un papel imprimible');
        $this->assertMatchesRegularExpression('/^TAL-\d{4}-[A-Z2-9]{8}$/', $certificado->folio);
        $this->assertSame('Ana Ruiz', $certificado->participant_name);
        $this->assertSame('Manejo Higiénico de Alimentos', $certificado->course_title);
        $this->assertSame('Panadería La Espiga', $certificado->company_name);
        $this->assertNotNull($certificado->issued_at);
    }

    public function test_reprobar_no_certifica(): void
    {
        $user = $this->usuario();
        $curso = $this->curso();

        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
            'answers' => [2],
        ])->assertJson(['passed' => false]);

        $this->assertDatabaseCount('course_certificates', 0);
    }

    public function test_volver_a_aprobar_no_emite_otro_ni_cambia_el_folio(): void
    {
        $user = $this->usuario();
        $curso = $this->curso();

        $aprobar = fn () => $this->actingAs($user)
            ->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", ['answers' => [0]]);

        $aprobar()->assertJson(['passed' => true]);
        $folio = DB::table('course_certificates')->where('user_id', $user->id)->value('folio');

        $aprobar()->assertJson(['passed' => true]);

        $this->assertDatabaseCount('course_certificates', 1);
        $this->assertSame($folio, DB::table('course_certificates')->where('user_id', $user->id)->value('folio'),
            'el folio que el colaborador ya tiene impreso no puede cambiar');
    }

    public function test_un_curso_sin_examen_tambien_certifica_al_completarlo(): void
    {
        $user = $this->usuario();
        $curso = $this->curso([], 'Video de bienvenida');

        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/progress", [
            'status' => 'completed',
        ])->assertStatus(200);

        $this->assertDatabaseHas('course_certificates', [
            'user_id' => $user->id,
            'course_title' => 'Video de bienvenida',
        ]);
    }

    public function test_los_certificados_del_colaborador_incluyen_los_cursos_completados_antes(): void
    {
        // El registro nació después de que ya hubiera progreso en las bases: quien aprobó ayer
        // no puede quedarse sin certificado para siempre.
        $user = $this->usuario();
        $curso = $this->curso();

        DB::table('user_course_progress')->insert([
            'user_id' => $user->id, 'course_id' => $curso->id, 'tenant_id' => 1,
            'status' => 'completed', 'score' => 90, 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $respuesta = $this->actingAs($user)->getJson('/api/v1/academy/certificates');

        $respuesta->assertStatus(200);
        $this->assertCount(1, $respuesta->json('certificates'));
        $this->assertSame(90, $respuesta->json('certificates.0.score'));

        // Y pedirlos otra vez no duplica.
        $this->actingAs($user)->getJson('/api/v1/academy/certificates');
        $this->assertDatabaseCount('course_certificates', 1);
    }

    public function test_el_folio_se_puede_verificar_sin_sesion(): void
    {
        $user = $this->usuario();
        $curso = $this->curso();

        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", [
            'answers' => [0],
        ]);

        $folio = DB::table('course_certificates')->where('user_id', $user->id)->value('folio');

        // Sin sesión: es el punto — quien recibe el papel comprueba que existe.
        $this->getJson("/api/v1/public/certificates/{$folio}")
            ->assertStatus(200)
            ->assertJson([
                'valid' => true,
                'participant_name' => 'Ana Ruiz',
                'course_title' => 'Manejo Higiénico de Alimentos',
                'company_name' => 'Panadería La Espiga',
            ]);
    }

    public function test_la_verificacion_no_filtra_nada_mas_del_expediente(): void
    {
        $user = $this->usuario();
        $curso = $this->curso();

        $this->actingAs($user)->postJson("/api/v1/academy/courses/{$curso->id}/quiz-attempt", ['answers' => [0]]);
        $folio = DB::table('course_certificates')->where('user_id', $user->id)->value('folio');

        $cuerpo = $this->getJson("/api/v1/public/certificates/{$folio}")->getContent();

        // Es una ruta pública: sólo puede decir lo que ya está impreso en el papel.
        $this->assertStringNotContainsString($user->email, $cuerpo);
        $this->assertStringNotContainsString('user_id', $cuerpo);
    }

    public function test_un_folio_inventado_no_existe(): void
    {
        $this->getJson('/api/v1/public/certificates/TAL-2026-XXXXXXXX')
            ->assertStatus(404)
            ->assertJson(['valid' => false]);
    }
}
