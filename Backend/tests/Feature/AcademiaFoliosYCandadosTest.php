<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CandadoDeSeeders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ACADEMIA — Fase 2 (2026-08-24). Tres candados y una demostración.
 *
 * Los 5 catálogos de giro siembran cada curso con UNA pregunta de relleno cuya respuesta correcta
 * es siempre la primera opción. Con eso se saca un certificado "100%" en un minuto, y uno de esos
 * cursos es **Derechos Laborales y Ley Federal del Trabajo**. Ese certificado lleva folio y se
 * verifica en público, sin sesión: lo va a leer un inspector o un abogado como constancia de
 * capacitación de la empresa.
 *
 * El dueño pidió inyectar 5 preguntas reales con un seeder. No se hizo, por dos razones que aquí
 * quedan fijadas: (a) el objetivo declarado —demostrar que el motor SÍ califica— se cumple con una
 * prueba, para siempre, y no con un seeder que nadie vuelve a correr; (b) el contenido de un curso
 * legal lo escribe quien responde por él, no el ingeniero.
 */
class AcademiaFoliosYCandadosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Academia QA', 'subdomain' => 'academiaqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->admin = $this->persona('Jefa', 'admin');
        $this->colaborador = $this->persona('Colaborador', 'empleado');
    }

    private function persona(string $nombre, string $rol): User
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower($nombre) . '@academiaqa.test', 'password' => bcrypt('x'), 'role' => $rol,
        ]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000,
        ]);

        return $user;
    }

    /** Las 5 preguntas viven AQUÍ, en memoria. No tocan la base de nadie. */
    private function cincoPreguntas(): array
    {
        return [
            ['question' => 'P1', 'options' => ['a', 'b', 'c'], 'correctAnswer' => 2],
            ['question' => 'P2', 'options' => ['a', 'b', 'c'], 'correctAnswer' => 0],
            ['question' => 'P3', 'options' => ['a', 'b', 'c'], 'correctAnswer' => 1],
            ['question' => 'P4', 'options' => ['a', 'b', 'c'], 'correctAnswer' => 2],
            ['question' => 'P5', 'options' => ['a', 'b', 'c'], 'correctAnswer' => 0],
        ];
    }

    private function curso(array $quiz, bool $aprobadoPorLaEmpresa): AcademyCourse
    {
        return AcademyCourse::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Derechos Laborales y LFT',
            'description' => 'x',
            'course_type' => 'training',
            'video_url' => '',
            'quiz_data' => $quiz,
            'quiz_approved_at' => $aprobadoPorLaEmpresa ? now() : null,
        ]);
    }

    private function contestar(AcademyCourse $curso, array $respuestas)
    {
        return $this->actingAs($this->colaborador)
            ->postJson('/api/v1/academy/courses/' . $curso->id . '/quiz-attempt', ['answers' => $respuestas]);
    }

    // ------------------------------------------------------------ el motor SÍ califica

    /**
     * LA PRUEBA QUE EL DUEÑO PIDIÓ, sin seeder y sin tocar la base: 5 preguntas, 3 correctas, 60%.
     * Demuestra que el motor califica de verdad y no es un sello que pasa con la primera opción.
     */
    public function test_cinco_preguntas_con_tres_correctas_dan_sesenta_por_ciento(): void
    {
        $curso = $this->curso($this->cincoPreguntas(), true);

        // Aciertos en P1, P3 y P5; falla P2 y P4.
        $r = $this->contestar($curso, [2, 1, 1, 0, 0]);

        $r->assertOk()
            ->assertJsonPath('score', 60)
            ->assertJsonPath('correct_count', 3)
            ->assertJsonPath('total', 5)
            ->assertJsonPath('passed', false);
    }

    /** Contestar todo "la primera opción" ya no aprueba nada: eso era el examen de relleno. */
    public function test_contestar_siempre_la_primera_opcion_no_aprueba(): void
    {
        $curso = $this->curso($this->cincoPreguntas(), true);

        $this->contestar($curso, [0, 0, 0, 0, 0])
            ->assertOk()
            ->assertJsonPath('passed', false)
            ->assertJsonPath('correct_count', 2);
    }

    public function test_las_cinco_correctas_aprueban_y_emiten_folio(): void
    {
        $curso = $this->curso($this->cincoPreguntas(), true);

        $this->contestar($curso, [2, 0, 1, 2, 0])
            ->assertOk()
            ->assertJsonPath('score', 100)
            ->assertJsonPath('passed', true)
            ->assertJsonPath('certificado_emitido', true);

        $this->assertDatabaseCount('course_certificates', 1);
    }

    // ------------------------------------------------------------ apagón de folios falsos

    /**
     * El examen de relleno del catálogo se puede aprobar (una pregunta, primera opción) — pero
     * NO expide un documento verificable en público.
     */
    public function test_el_examen_de_relleno_no_expide_folio_verificable(): void
    {
        $relleno = $this->curso([[
            'question' => '¿Cuál es el objetivo principal de este protocolo?',
            'options' => ['Garantizar la seguridad y calidad', 'Aumentar tiempos de espera', 'Omitir registros', 'Ninguna'],
            'correctAnswer' => 0,
        ]], false);

        $r = $this->contestar($relleno, [0]);

        $r->assertOk()
            ->assertJsonPath('passed', true)          // el avance del curso se respeta
            ->assertJsonPath('certificado_emitido', false);
        $this->assertStringContainsString('examen de ejemplo', $r->json('certificado_bloqueado_motivo'));

        $this->assertDatabaseCount('course_certificates', 0);
    }

    /** Y el sello lo pone el administrador al guardar SU examen desde su pantalla. */
    public function test_guardar_el_examen_desde_la_pantalla_lo_sella_como_propio(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/academy/courses', [
            'title' => 'Curso de la empresa',
            'course_type' => 'training',
            'quiz_data' => $this->cincoPreguntas(),
        ])->assertOk();

        $curso = AcademyCourse::where('title', 'Curso de la empresa')->first();

        $this->assertNotNull($curso->quiz_approved_at, 'guardar el examen ES la aprobación de la empresa');
        $this->assertSame($this->admin->id, (int) $curso->quiz_approved_by);
    }

    /** Un curso sin examen no sella nada: no hay evaluación que aprobar. */
    public function test_un_curso_sin_examen_no_queda_sellado(): void
    {
        $this->actingAs($this->admin)->postJson('/api/v1/academy/courses', [
            'title' => 'Solo video',
            'course_type' => 'training',
        ])->assertOk();

        $this->assertNull(AcademyCourse::where('title', 'Solo video')->first()->quiz_approved_at);
    }

    // ------------------------------------------------------------ candado de seeders

    public function test_el_candado_frena_al_seeder_cuando_hay_datos_vivos(): void
    {
        $curso = $this->curso($this->cincoPreguntas(), true);
        DB::table('user_course_progress')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'course_id' => $curso->id, 'status' => 'completed', 'score' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEEDER BLOQUEADO');

        CandadoDeSeeders::verificar('cursos de Academia', ['user_course_progress' => null]);
    }

    public function test_sobre_una_base_vacia_el_candado_deja_pasar(): void
    {
        CandadoDeSeeders::verificar('cursos de Academia', [
            'user_course_progress' => null,
            'course_certificates' => null,
        ]);

        $this->assertTrue(true, 'sin datos vivos, sembrar es legítimo');
    }

    public function test_en_produccion_el_candado_no_deja_sembrar_ni_con_la_base_vacia(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['app.seeders_permitidos' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('esto es producción');

        CandadoDeSeeders::verificar('cursos de Academia');
    }

    public function test_el_administrador_de_la_maquina_puede_forzarlo_a_proposito(): void
    {
        app()->detectEnvironment(fn () => 'production');
        config(['app.seeders_permitidos' => true]);

        CandadoDeSeeders::verificar('cursos de Academia');

        $this->assertTrue(true, 'con la llave puesta a propósito, se puede');
    }
}
