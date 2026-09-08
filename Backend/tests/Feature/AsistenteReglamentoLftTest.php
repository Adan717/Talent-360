<?php

namespace Tests\Feature;

use App\Models\LftSetting;
use App\Models\User;
use App\Support\JornadaExtraordinaria;
use App\Support\PropuestaDeReglamentoLft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Asistente del reglamento interior, real (Plan A3, 2026-09-07).
 *
 * Antes era una animación que rellenaba números fijos sin leer el archivo. Ahora el servidor lee
 * el PDF/TXT, se lo manda a la IA y devuelve una PROPUESTA depurada. Lo que se protege aquí:
 *  - el texto del reglamento SÍ viaja a la IA (no valores fijos) y lo que vuelve se devuelve;
 *  - lo que la IA diga fuera de la lista blanca o fuera de la ley se descarta o se recorta, y se avisa;
 *  - NADA se escribe en `lft_settings`: guardar es del admin, después;
 *  - sin llave de IA la respuesta es un error claro, no un 500 ni un silencio;
 *  - sólo el admin puede usarlo.
 */
class AsistenteReglamentoLftTest extends TestCase
{
    use RefreshDatabase;

    private const REGLAMENTO = <<<TXT
REGLAMENTO INTERIOR DE TRABAJO — DECORARTE S.A. DE C.V.

CAPÍTULO IV. DE LA ASISTENCIA Y PUNTUALIDAD
Artículo 12. Los trabajadores deberán registrar su entrada a la hora fijada en su horario. Se
concederá una tolerancia de quince minutos; pasado ese lapso el registro se considerará retardo.
Artículo 13. Tres retardos en una misma semana equivaldrán a una falta injustificada.
Artículo 14. La acumulación de tres faltas injustificadas dará lugar a llamada de atención por
escrito, y la de cuatro a suspensión sin goce de sueldo.
Artículo 15. Al regresar de la comida se concederán diez minutos de tolerancia.
Artículo 16. El tiempo extraordinario no excederá de tres horas diarias ni de tres veces por semana.
TXT;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'Reglamento QA', 'subdomain' => 'reglamentoqa', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Con llave de OpenAI: el servicio elige ese proveedor y sale a chat/completions.
        config(['services.openai.api_key' => 'sk-prueba', 'services.gemini.api_key' => '']);
    }

    private function persona(string $rol): User
    {
        $user = User::factory()->create(['role' => $rol]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        return $user->refresh();
    }

    /** Lo que "diría" la IA: valores del texto, más basura que la depuración debe atrapar. */
    private function respuestaDeLaIa(?array $reglas = null): array
    {
        $reglas ??= [
            'late_tolerance_minutes' => ['valor' => 15, 'cita' => 'Se concederá una tolerancia de quince minutos', 'confianza' => 'alta'],
            'meal_tolerance_minutes' => ['valor' => '10', 'cita' => 'diez minutos de tolerancia', 'confianza' => 'alta'],
            'lates_per_absence' => ['valor' => 3, 'cita' => 'Tres retardos', 'confianza' => 'alta'],
            'absences_for_warning' => ['valor' => 3, 'cita' => 'tres faltas', 'confianza' => 'alta'],
            'absences_for_suspension' => ['valor' => 4, 'cita' => 'cuatro a suspensión', 'confianza' => 'alta'],
            'overtime_weekly_cap_minutes' => ['valor' => 900, 'cita' => 'tres horas diarias', 'confianza' => 'media'],
            'late_action_mode' => ['valor' => 'multa', 'cita' => '', 'confianza' => 'baja'],
            'deduct_absence_day' => ['valor' => 'sí', 'cita' => 'sin goce de sueldo', 'confianza' => 'media'],
            'salario_minimo' => ['valor' => 999, 'cita' => 'inventado', 'confianza' => 'alta'],
        ];

        return [
            'choices' => [[
                'message' => ['content' => json_encode([
                    'reglas' => $reglas,
                    'articulos' => [['referencia' => 'Artículo 12', 'resumen' => 'Tolerancia de 15 minutos a la entrada.']],
                    'advertencias' => ['El artículo 16 repite el límite del art. 66 de la LFT.'],
                ])],
            ]],
        ];
    }

    public function test_lee_el_texto_lo_manda_a_la_ia_y_devuelve_una_propuesta_depurada_sin_guardar(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->respuestaDeLaIa(), 200)]);

        // Configuración previa de la empresa: la lectura NO la debe tocar.
        LftSetting::create(['tenant_id' => 1, 'late_tolerance_minutes' => 10, 'meal_tolerance_minutes' => 15,
            'rest_tolerance_minutes' => 10, 'lates_per_absence' => 3, 'absences_for_warning' => 3,
            'absences_for_suspension' => 4, 'deduct_absence_day' => true, 'proportional_rest_day' => true,
            'paid_rest_day' => true, 'late_action_mode' => 'deduct']);

        $respuesta = $this->actingAs($this->persona('admin'))
            ->post('/api/v1/admin/lft/leer-reglamento', [
                'archivo' => UploadedFile::fake()->createWithContent('reglamento.txt', self::REGLAMENTO),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('fuente.nombre', 'reglamento.txt');

        // El reglamento REAL viajó a la IA (no una animación con números fijos).
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.openai.com')
            && str_contains($req->body(), 'tolerancia de quince minutos'));

        // Lo que vino del texto se propone, con su cita.
        $respuesta->assertJsonPath('propuesta.late_tolerance_minutes.valor', 15)
            ->assertJsonPath('propuesta.late_tolerance_minutes.cita', 'Se concederá una tolerancia de quince minutos')
            ->assertJsonPath('propuesta.meal_tolerance_minutes.valor', 10) // "10" → 10
            ->assertJsonPath('propuesta.deduct_absence_day.valor', true) // "sí" → true
            ->assertJsonPath('articulos.0.referencia', 'Artículo 12');

        // Lo que la ley no permite se recorta al techo y se avisa; lo inválido o desconocido se descarta.
        $respuesta->assertJsonPath('propuesta.overtime_weekly_cap_minutes.valor', JornadaExtraordinaria::TECHO_LFT_MINUTOS_SEMANA);
        $this->assertArrayNotHasKey('late_action_mode', $respuesta->json('propuesta'));
        $this->assertArrayNotHasKey('salario_minimo', $respuesta->json('propuesta'));
        $this->assertContains('salario_minimo', $respuesta->json('descartadas'));
        $this->assertContains('late_action_mode', $respuesta->json('descartadas'));
        $this->assertTrue(collect($respuesta->json('advertencias'))->contains(fn ($a) => str_contains($a, 'art. 66')));
        $this->assertContains('El artículo 16 repite el límite del art. 66 de la LFT.', $respuesta->json('advertencias'));

        // NADA se guardó: la configuración sigue como estaba.
        $this->assertDatabaseHas('lft_settings', ['tenant_id' => 1, 'late_tolerance_minutes' => 10, 'meal_tolerance_minutes' => 15]);
        $this->assertSame(1, LftSetting::count());
    }

    public function test_tambien_lee_un_pdf_de_verdad(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->respuestaDeLaIa([
            'late_tolerance_minutes' => ['valor' => 15, 'cita' => 'quince minutos', 'confianza' => 'alta'],
        ]), 200)]);

        // Un PDF generado aquí mismo (dompdf ya está en el proyecto): así se ejercita el lector real.
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML('<p>' . nl2br(e(self::REGLAMENTO)) . '</p>')->output();

        $this->actingAs($this->persona('admin'))
            ->post('/api/v1/admin/lft/leer-reglamento', [
                'archivo' => UploadedFile::fake()->createWithContent('reglamento.pdf', $pdf),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('propuesta.late_tolerance_minutes.valor', 15);

        Http::assertSent(fn ($req) => str_contains($req->body(), 'quince minutos'));
    }

    public function test_sin_llave_de_ia_responde_un_error_claro_y_no_sale_a_ningun_lado(): void
    {
        config(['services.openai.api_key' => '', 'services.gemini.api_key' => '']);
        Http::fake();

        $this->actingAs($this->persona('admin'))
            ->post('/api/v1/admin/lft/leer-reglamento', ['texto' => self::REGLAMENTO], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonFragment(['message' => 'Sin llave de IA configurada en el servidor: el asistente no puede leer el reglamento. '
                . 'Puedes capturar las tolerancias a mano en esta misma pantalla.']);

        Http::assertNothingSent();
    }

    public function test_si_la_ia_falla_lo_dice_con_502_y_no_guarda(): void
    {
        Http::fake(['api.openai.com/*' => Http::response('caído', 500)]);

        $this->actingAs($this->persona('admin'))
            ->post('/api/v1/admin/lft/leer-reglamento', ['texto' => self::REGLAMENTO], ['Accept' => 'application/json'])
            ->assertStatus(502)
            ->assertJsonPath('success', false);

        $this->assertSame(0, LftSetting::count());
    }

    public function test_un_archivo_sin_texto_o_de_otro_tipo_se_rechaza(): void
    {
        Http::fake();
        $admin = $this->persona('admin');

        $this->actingAs($admin)
            ->post('/api/v1/admin/lft/leer-reglamento', [
                'archivo' => UploadedFile::fake()->createWithContent('vacio.txt', 'hola'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->post('/api/v1/admin/lft/leer-reglamento', [
                'archivo' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_solo_el_admin_puede_usar_el_asistente(): void
    {
        Http::fake();

        $this->actingAs($this->persona('supervisor'))
            ->post('/api/v1/admin/lft/leer-reglamento', ['texto' => self::REGLAMENTO], ['Accept' => 'application/json'])
            ->assertStatus(403);
        $this->actingAs($this->persona('empleado'))
            ->post('/api/v1/admin/lft/leer-reglamento', ['texto' => self::REGLAMENTO], ['Accept' => 'application/json'])
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    // --- La depuración, como función pura ---------------------------------------------------

    public function test_la_depuracion_avisa_si_la_suspension_llega_antes_que_la_llamada_de_atencion(): void
    {
        $salida = PropuestaDeReglamentoLft::depurar(['reglas' => [
            'absences_for_warning' => ['valor' => 5],
            'absences_for_suspension' => ['valor' => 2],
            'late_tolerance_minutes' => ['valor' => -3],
            'rest_tolerance_minutes' => ['valor' => 9999],
            'lates_per_absence' => ['valor' => 0],
        ]]);

        $this->assertSame(5, $salida['propuesta']['absences_for_warning']['valor']);
        $this->assertSame(2, $salida['propuesta']['absences_for_suspension']['valor']);
        $this->assertArrayNotHasKey('late_tolerance_minutes', $salida['propuesta']);
        $this->assertArrayNotHasKey('rest_tolerance_minutes', $salida['propuesta']);
        $this->assertArrayNotHasKey('lates_per_absence', $salida['propuesta']);
        $this->assertTrue(collect($salida['advertencias'])->contains(fn ($a) => str_contains($a, 'MENOS faltas')));
    }
}
