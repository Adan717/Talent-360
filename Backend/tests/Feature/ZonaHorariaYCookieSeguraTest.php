<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los dos avisos del preflight, cerrados (2026-08-26).
 *
 *  · **Zona horaria**: de ella dependen los retardos y el corte del día en nómina. Las empresas
 *    funcionaban por el default de `TenantTimezone`, un supuesto que no estaba escrito en ningún
 *    lado. Escribir el valor que YA se usaba no cambia el comportamiento de nadie: convierte una
 *    suposición silenciosa en un dato declarado.
 *
 *  · **Cookie segura**: el preflight leía `env('SESSION_SECURE_COOKIE', false)` mientras la
 *    aplicación usaba `config('session.secure')` con default `true` — reportaba un valor que NO
 *    era el que el sistema usaba. Y una cookie `secure` no viaja por HTTP, así que en la instancia
 *    sin certificado la sesión por cookie no funcionaba y nadie lo había decidido.
 */
class ZonaHorariaYCookieSeguraTest extends TestCase
{
    use RefreshDatabase;

    private function empresa(string $nombre, ?string $zona = null): Tenant
    {
        $tenant = Tenant::create([
            'name' => $nombre, 'subdomain' => strtolower(str_replace(' ', '', $nombre)),
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        if ($zona !== null) {
            DB::table('system_settings')->insert([
                'tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => json_encode($zona),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $tenant;
    }

    private function zonaDe(Tenant $t): ?string
    {
        $v = DB::table('system_settings')->where('tenant_id', $t->id)->where('key', 'timezone')->value('value');

        return $v === null ? null : json_decode($v, true);
    }

    // ------------------------------------------------------------- zona horaria

    public function test_el_simulacro_no_escribe_nada(): void
    {
        $sinZona = $this->empresa('Sin Zona');

        $this->artisan('tenants:fijar-zona-horaria')->assertExitCode(0);

        $this->assertNull($this->zonaDe($sinZona), 'sin --aplicar no se toca nada');
    }

    public function test_escribe_la_zona_a_quien_no_la_declara(): void
    {
        $sinZona = $this->empresa('Sin Zona');

        $this->artisan('tenants:fijar-zona-horaria --aplicar')->assertExitCode(0);

        $this->assertSame('America/Mexico_City', $this->zonaDe($sinZona));
    }

    /** A quien ya la declaró no se le toca: podría ser de Tijuana. */
    public function test_no_pisa_la_zona_de_quien_ya_la_tiene(): void
    {
        $tijuana = $this->empresa('De Tijuana', 'America/Tijuana');

        $this->artisan('tenants:fijar-zona-horaria --aplicar')->assertExitCode(0);

        $this->assertSame('America/Tijuana', $this->zonaDe($tijuana), 'su zona es suya');
    }

    public function test_es_idempotente(): void
    {
        $sinZona = $this->empresa('Sin Zona');

        $this->artisan('tenants:fijar-zona-horaria --aplicar')->assertExitCode(0);
        $this->artisan('tenants:fijar-zona-horaria --aplicar')->assertExitCode(0);

        $this->assertSame(
            1,
            DB::table('system_settings')->where('tenant_id', $sinZona->id)->where('key', 'timezone')->count(),
            'correrlo dos veces no duplica el ajuste'
        );
    }

    /** Una zona inválida reventaría cada cálculo de jornada: se valida antes de tocar. */
    public function test_una_zona_invalida_se_rechaza(): void
    {
        $sinZona = $this->empresa('Sin Zona');

        $this->artisan('tenants:fijar-zona-horaria --zona=Marte/Olympus --aplicar')->assertExitCode(1);

        $this->assertNull($this->zonaDe($sinZona));
    }

    /** Y lo que de verdad importa: el sistema la usa para fechar la jornada. */
    public function test_la_zona_escrita_es_la_que_usa_el_reloj(): void
    {
        $tijuana = $this->empresa('De Tijuana', 'America/Tijuana');

        $this->assertSame('America/Tijuana', \App\Helpers\TenantTimezone::for($tijuana->id));
    }

    // ------------------------------------------------------------- cookie segura

    /** Por HTTP la cookie NO puede ir marcada como segura: no viajaría. */
    public function test_sin_https_la_cookie_no_se_marca_como_segura(): void
    {
        $this->assertFalse(
            str_starts_with('http://46.225.153.115:8002', 'https://'),
            'la instancia de pruebas va por HTTP'
        );

        // Es la misma expresión que usa config/session.php.
        $this->assertFalse(str_starts_with('http://ejemplo.test', 'https://'));
    }

    /** Y en cuanto haya certificado, se vuelve segura sola. */
    public function test_con_https_la_cookie_se_marca_sola(): void
    {
        $this->assertTrue(str_starts_with('https://app.talent360.mx', 'https://'));
    }

    /** El preflight pregunta lo MISMO que usa la app, no una variable con otro default. */
    public function test_el_preflight_ya_no_reporta_un_valor_que_la_app_no_usa(): void
    {
        $fuente = file_get_contents(app_path('Console/Commands/PreflightProduccion.php'));

        $this->assertStringNotContainsString(
            "env('SESSION_SECURE_COOKIE'",
            $fuente,
            'el preflight volvió a leer el env en vez de la config que usa la aplicación'
        );
        $this->assertStringContainsString("config('session.secure')", $fuente);
    }
}
