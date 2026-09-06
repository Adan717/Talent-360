<?php

namespace Tests\Feature;

use App\Helpers\TenantTimezone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La zona horaria de la empresa se valida AL ESCRIBIRLA (2026-09-05).
 *
 * De `system_settings.timezone` dependen los retardos y el corte del día en nómina: la lee
 * `TenantTimezone::for()` en cada cálculo de jornada. Antes de este candado, `POST /sync/settings`
 * guardaba cualquier cadena sin mirarla y `TenantTimezone` caía a `America/Mexico_City` en
 * SILENCIO cuando la zona no existía — la empresa quedaba convencida de estar en Tijuana mientras
 * el sistema le seguía fichando una hora corrida. Un ajuste que se descarta sin avisar es peor que
 * uno que falla: nadie va a buscar el error donde no hubo error.
 */
class ZonaHorariaValidadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'pro',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // La empresa arranca declarando Tijuana: así se ve si un rechazo la deja intacta.
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => 1, 'key' => 'timezone'],
            ['value' => 'America/Tijuana', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        return $user->refresh();
    }

    private function zonaGuardada(): ?string
    {
        return DB::table('system_settings')->where('tenant_id', 1)->where('key', 'timezone')->value('value');
    }

    /** CANDADO: una zona inventada se rechaza y NO pisa la que la empresa tenía. */
    public function test_zona_invalida_da_422_y_la_zona_guardada_no_cambia(): void
    {
        $respuesta = $this->actingAs($this->admin())->postJson('/api/v1/sync/settings', [
            'key' => 'timezone',
            'value' => 'America/Marte',
        ]);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('zona horaria', strtolower($respuesta->json('error') ?? ''));

        $this->assertSame('America/Tijuana', $this->zonaGuardada());
        $this->assertSame('America/Tijuana', TenantTimezone::for(1));
    }

    /** Basura que ni siquiera parece una zona (el caso que antes se descartaba sin avisar). */
    public function test_una_cadena_cualquiera_tambien_da_422(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/sync/settings', ['key' => 'timezone', 'value' => 'Tijuana'])
            ->assertStatus(422);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/sync/settings', ['key' => 'timezone', 'value' => ''])
            ->assertStatus(422);

        $this->assertSame('America/Tijuana', $this->zonaGuardada());
    }

    /** Una zona real sí entra, y es la que el resto del sistema empieza a usar. */
    public function test_zona_valida_se_guarda_y_la_lee_tenant_timezone(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/v1/sync/settings', ['key' => 'timezone', 'value' => 'America/Cancun'])
            ->assertStatus(200);

        $this->assertSame('America/Cancun', $this->zonaGuardada());
        $this->assertSame('America/Cancun', TenantTimezone::for(1));
    }

    /**
     * CANDADO: en el envío por lote (el que usa la pantalla de Configuración al guardar varios
     * ajustes de golpe) una zona inválida no debe dejar el lote a medias — se valida TODO antes
     * de escribir NADA.
     */
    public function test_lote_con_zona_invalida_no_escribe_ningun_ajuste(): void
    {
        $respuesta = $this->actingAs($this->admin())->postJson('/api/v1/sync/settings', [
            'company_name' => 'Nombre Nuevo',
            'timezone' => 'Nope/Nope',
        ]);

        $respuesta->assertStatus(422);

        $this->assertSame('America/Tijuana', $this->zonaGuardada());
        // (No se usa assertDatabaseMissing: la fila 'company_name' del tenant 1 ya viene sembrada
        // por las migraciones; lo que importa es que NO se le escribió el valor del lote rechazado.)
        $this->assertNotSame(
            'Nombre Nuevo',
            DB::table('system_settings')->where('tenant_id', 1)->where('key', 'company_name')->value('value')
        );
    }
}
