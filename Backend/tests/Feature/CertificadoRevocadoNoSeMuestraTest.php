<?php

namespace Tests\Feature;

use App\Models\AcademyCourse;
use App\Models\CourseCertificate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un certificado revocado NUNCA se muestra en público (2026-08-24).
 *
 * El apagón de folios frena las constancias nuevas, pero ya había expedidas sobre el examen de
 * relleno del catálogo — una de ellas dice "Derechos Laborales y Ley Federal del Trabajo" y se
 * verifica sin sesión. Se revocan, no se borran: fueron un hecho y borrarlas dejaría la consulta
 * indistinguible de un folio inventado, además de perder la evidencia de por qué se retiraron.
 *
 * Lo que esta prueba fija es que revocar **sirva de algo**: que la ventana pública no filtre ni el
 * curso, ni el nombre de la persona, ni la empresa.
 */
class CertificadoRevocadoNoSeMuestraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $colaborador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Revoca QA', 'subdomain' => 'revocaqa', 'plan' => 'enterprise', 'is_active' => true]);
        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Miguel Prueba',
            'email' => 'miguel@revocaqa.test', 'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
    }

    private function curso(bool $examenPropio): AcademyCourse
    {
        return AcademyCourse::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Derechos Laborales y LFT',
            'description' => 'x',
            'course_type' => 'training',
            'quiz_data' => [['question' => 'P', 'options' => ['a', 'b'], 'correctAnswer' => 0]],
            'quiz_approved_at' => $examenPropio ? now() : null,
        ]);
    }

    private function certificado(AcademyCourse $curso, ?string $revocado = null): CourseCertificate
    {
        return CourseCertificate::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->colaborador->id,
            'course_id' => $curso->id,
            'folio' => 'TAL-2026-PRUEBA01',
            'issued_at' => now()->subDay(),
            'score' => 100,
            'participant_name' => 'Miguel Prueba',
            'course_title' => 'Derechos Laborales y LFT',
            'company_name' => 'Revoca QA',
            'revoked_at' => $revocado,
        ]);
    }

    public function test_un_folio_vigente_si_verifica(): void
    {
        $this->certificado($this->curso(true));

        $this->getJson('/api/v1/public/certificates/TAL-2026-PRUEBA01')
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('participant_name', 'Miguel Prueba');
    }

    public function test_un_folio_revocado_responde_404_y_no_filtra_nada(): void
    {
        $this->certificado($this->curso(false), now()->toDateTimeString());

        $res = $this->getJson('/api/v1/public/certificates/TAL-2026-PRUEBA01');

        $res->assertStatus(404)->assertJsonPath('valid', false);
        $this->assertStringContainsString('revocado', $res->json('message'));

        // Ni el curso, ni la persona, ni la empresa: nada de eso sale de aquí.
        $crudo = $res->getContent();
        $this->assertStringNotContainsString('Derechos Laborales', $crudo);
        $this->assertStringNotContainsString('Miguel Prueba', $crudo);
        $this->assertStringNotContainsString('Revoca QA', $crudo);
    }

    /** El certificado NO se borra: la empresa conserva la evidencia y el motivo. */
    public function test_revocar_conserva_el_registro_y_su_motivo(): void
    {
        $curso = $this->curso(false);
        $this->certificado($curso);

        $this->artisan('academia:revocar-folios-sin-examen --aplicar')->assertExitCode(0);

        $this->assertDatabaseCount('course_certificates', 1);
        $fila = CourseCertificate::withoutGlobalScopes()->first();
        $this->assertNotNull($fila->revoked_at);
        $this->assertStringContainsString('examen de ejemplo', $fila->revoked_reason);
    }

    public function test_el_simulacro_no_escribe_nada(): void
    {
        $this->certificado($this->curso(false));

        $this->artisan('academia:revocar-folios-sin-examen')->assertExitCode(0);

        $this->assertNull(CourseCertificate::withoutGlobalScopes()->first()->revoked_at);
    }

    /** Una constancia respaldada por un examen de la empresa NO se toca. */
    public function test_no_revoca_los_certificados_legitimos(): void
    {
        $this->certificado($this->curso(true));

        $this->artisan('academia:revocar-folios-sin-examen --aplicar')->assertExitCode(0);

        $this->assertNull(CourseCertificate::withoutGlobalScopes()->first()->revoked_at);
        $this->getJson('/api/v1/public/certificates/TAL-2026-PRUEBA01')->assertOk();
    }

    public function test_el_colaborador_tampoco_ve_en_su_lista_uno_revocado(): void
    {
        $this->certificado($this->curso(false), now()->toDateTimeString());

        $this->actingAs($this->colaborador)
            ->getJson('/api/v1/academy/certificates')
            ->assertOk()
            ->assertJsonCount(0, 'certificates');
    }

    /**
     * "Mis certificados" era una SEGUNDA puerta de emisión y se saltaba el apagón: con sólo abrir
     * la pantalla se expedía la constancia de un curso con el examen de relleno.
     */
    public function test_abrir_mis_certificados_no_expide_folios_del_examen_de_relleno(): void
    {
        $curso = $this->curso(false);
        \DB::table('user_course_progress')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'course_id' => $curso->id, 'status' => 'completed', 'score' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->colaborador)
            ->getJson('/api/v1/academy/certificates')
            ->assertOk()
            ->assertJsonCount(0, 'certificates');

        $this->assertDatabaseCount('course_certificates', 0);
    }
}
