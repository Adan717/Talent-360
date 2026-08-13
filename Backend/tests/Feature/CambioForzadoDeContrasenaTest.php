<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bloque 1 del plan (2026-08-13) — el consejo tumbó la decisión D4: las contraseñas
 * conocidas SÍ se rotan, con cambio forzado al siguiente inicio de sesión.
 *
 * El criterio de terminado del plan, literal: "una cuenta marcada no puede usar ninguna
 * ruta salvo la de cambiar su contraseña, y la prueba lo demuestra fallando sin el arreglo".
 * Sin el middleware ForcePasswordChange, test_una_cuenta_marcada_no_puede_usar_otra_ruta
 * falla (el /me respondería 200).
 */
class CambioForzadoDeContrasenaTest extends TestCase
{
    use RefreshDatabase;

    private User $marcado;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Rotación QA', 'subdomain' => 'rotacionqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->marcado = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Cuenta Vieja', 'email' => 'vieja@rotacionqa.test',
            'password' => bcrypt('password123'), 'role' => 'admin',
            'must_change_password' => true,
        ]);
    }

    public function test_el_login_de_una_cuenta_marcada_funciona_y_avisa(): void
    {
        $respuesta = $this->postJson('/api/v1/login', [
            'email' => 'vieja@rotacionqa.test',
            'password' => 'password123',
        ]);

        $respuesta->assertOk()->assertJsonPath('user.must_change_password', true);
    }

    public function test_una_cuenta_marcada_no_puede_usar_otra_ruta(): void
    {
        $this->actingAs($this->marcado)->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'must_change_password');
    }

    public function test_la_ruta_de_cambio_si_esta_abierta_y_al_usarla_se_levanta_el_candado(): void
    {
        $this->actingAs($this->marcado)->postJson('/api/v1/me/change-password', [
            'current_password' => 'password123',
            'new_password' => 'UnaBuena#2026',
            'new_password_confirmation' => 'UnaBuena#2026',
        ])->assertOk();

        $this->assertFalse($this->marcado->fresh()->must_change_password);

        $this->actingAs($this->marcado->fresh())->getJson('/api/v1/me')->assertOk();
    }

    public function test_no_se_puede_cambiar_a_otra_contrasena_conocida(): void
    {
        $this->actingAs($this->marcado)->postJson('/api/v1/me/change-password', [
            'current_password' => 'password123',
            'new_password' => '123456',
            'new_password_confirmation' => '123456',
        ])->assertStatus(422);

        $this->assertTrue($this->marcado->fresh()->must_change_password);
    }

    public function test_una_cuenta_sin_marca_no_se_ve_afectada(): void
    {
        $normal = User::create([
            'tenant_id' => $this->marcado->tenant_id, 'name' => 'Cuenta Sana',
            'email' => 'sana@rotacionqa.test', 'password' => bcrypt('UnaBuena#2026'),
            'role' => 'admin',
        ]);

        $this->actingAs($normal)->getJson('/api/v1/me')->assertOk();
    }

    public function test_el_comando_marca_las_conocidas_y_respeta_las_demas(): void
    {
        $this->marcado->update(['must_change_password' => false]);

        $fuerte = User::create([
            'tenant_id' => $this->marcado->tenant_id, 'name' => 'Fuerte',
            'email' => 'fuerte@rotacionqa.test', 'password' => bcrypt('UnaBuena#2026'),
            'role' => 'empleado',
        ]);
        $seis = User::create([
            'tenant_id' => $this->marcado->tenant_id, 'name' => 'Seis',
            'email' => 'seis@rotacionqa.test', 'password' => bcrypt('123456'),
            'role' => 'empleado',
        ]);

        $this->artisan('usuarios:marcar-contrasenas-conocidas')->assertSuccessful();

        $this->assertTrue($this->marcado->fresh()->must_change_password, 'password123 se marca');
        $this->assertTrue($seis->fresh()->must_change_password, '123456 se marca');
        $this->assertFalse($fuerte->fresh()->must_change_password, 'una contraseña propia no se toca');
    }

    /**
     * Revisión adversarial: Sanctum no caduca tokens (expiration=null). Sin la expulsión,
     * quien entró con la contraseña vieja conservaba un token ETERNO que revivía en cuanto
     * el dueño legítimo completaba el cambio forzado.
     */
    public function test_cambiar_la_contrasena_expulsa_las_otras_sesiones(): void
    {
        $tokenIntruso = $this->marcado->createToken('auth_token')->plainTextToken;
        $tokenPropio = $this->marcado->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenPropio}")
            ->postJson('/api/v1/me/change-password', [
                'current_password' => 'password123',
                'new_password' => 'UnaBuena#2026',
                'new_password_confirmation' => 'UnaBuena#2026',
            ])->assertOk();

        $this->assertSame(1, $this->marcado->tokens()->count(), 'sólo sobrevive la sesión que hizo el cambio');

        // El token del intruso ya no autentica (se limpia el caché del guard, no la app:
        // refreshApplication tiraría la sqlite en memoria).
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$tokenIntruso}")
            ->getJson('/api/v1/me')->assertStatus(401);
    }

    /** Revisión adversarial: el platform admin nacía con `123456` literal y quedaba fuera de todo. */
    public function test_el_platform_admin_con_contrasena_conocida_tambien_entra_a_la_rotacion(): void
    {
        $platform = \App\Models\PlatformUser::create([
            'name' => 'Master', 'email' => 'master@plataforma.test',
            'password' => bcrypt('123456'), 'role' => 'platform_admin', 'is_active' => true,
        ]);

        $this->artisan('usuarios:marcar-contrasenas-conocidas')->assertSuccessful();
        $this->assertTrue($platform->fresh()->must_change_password, 'el comando también revisa platform_users');

        // fresh(): el comando marcó la fila en BD, no esta instancia en memoria.
        $this->actingAs($platform->fresh())->getJson('/api/v1/me')
            ->assertStatus(403)->assertJsonPath('code', 'must_change_password');

        $this->actingAs($platform->fresh())->postJson('/api/v1/me/change-password', [
            'current_password' => '123456',
            'new_password' => 'SoloMia#2026',
            'new_password_confirmation' => 'SoloMia#2026',
        ])->assertOk();

        $this->assertFalse($platform->fresh()->must_change_password);
    }

    /** Revisión adversarial: el enlace de reset era la puerta trasera del blocklist. */
    public function test_el_enlace_de_reset_no_acepta_conocidas_y_expulsa_sesiones(): void
    {
        \Illuminate\Support\Facades\DB::table('password_reset_tokens')->insert([
            'email' => 'vieja@rotacionqa.test',
            'token' => \Illuminate\Support\Facades\Hash::make('token-de-reset'),
            'created_at' => now(),
        ]);
        $this->marcado->createToken('auth_token');

        // Volver a password123 por el enlace: rechazado, y la marca sigue.
        $this->postJson('/api/v1/reset-password', [
            'email' => 'vieja@rotacionqa.test', 'token' => 'token-de-reset',
            'password' => 'password123',
        ])->assertStatus(422);
        $this->assertTrue($this->marcado->fresh()->must_change_password);

        // Con una propia: pasa, desmarca y mata todas las sesiones vivas.
        $this->postJson('/api/v1/reset-password', [
            'email' => 'vieja@rotacionqa.test', 'token' => 'token-de-reset',
            'password' => 'SoloMia#2026',
        ])->assertOk();
        $this->assertFalse($this->marcado->fresh()->must_change_password);
        $this->assertSame(0, $this->marcado->tokens()->count());
    }

    /**
     * Revisión adversarial: la tableta del kiosco ancla la sesión de UNA persona; si esa
     * cuenta está marcada, bloquear /kiosk/punch dejaría sin fichar a toda la tienda.
     */
    public function test_el_kiosco_no_se_bloquea_por_la_marca_del_ancla(): void
    {
        $respuesta = $this->actingAs($this->marcado)->postJson('/api/v1/kiosk/punch', []);

        $this->assertNotSame('must_change_password', $respuesta->json('code'),
            'el ponche por PIN no puede morir por la contraseña vieja del ancla');
    }

    /** Revisión adversarial: el alta con contraseña tecleada por el admin debe nacer marcada. */
    public function test_el_alta_con_contrasena_del_admin_nace_marcada(): void
    {
        $admin = User::create([
            'tenant_id' => $this->marcado->tenant_id, 'name' => 'Admin Sano',
            'email' => 'adminsano@rotacionqa.test', 'password' => bcrypt('SoloMia#2026'),
            'role' => 'admin',
        ]);

        $this->actingAs($admin)->postJson('/api/v1/employees', [
            'name' => 'Nueva Persona', 'email' => 'nueva@rotacionqa.test',
            'role' => 'empleado', 'hire_date' => '2026-08-13', 'salary' => 3000,
            'password' => 'LaPusoElAdmin#1',
        ])->assertSuccessful();

        $cuenta = User::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->where('email', 'nueva@rotacionqa.test')->first();
        $this->assertNotNull($cuenta);
        $this->assertTrue($cuenta->must_change_password, 'la contraseña la conoce el admin');
    }

    public function test_la_contrasena_puesta_por_el_admin_de_plataforma_marca_la_cuenta(): void
    {
        $this->marcado->update(['must_change_password' => false]);

        $platform = \App\Models\PlatformUser::create([
            'name' => 'Root', 'email' => 'root@plataforma.test',
            'password' => bcrypt('OtraBuena#2026'), 'role' => 'platform_admin',
        ]);

        $this->actingAs($platform)->postJson(
            "/api/v1/platform/tenants/{$this->marcado->tenant_id}/reset-password",
            ['password' => 'Temporal#2026']
        )->assertOk();

        $this->assertTrue($this->marcado->fresh()->must_change_password, 'una contraseña puesta por otro obliga a cambiarla');
    }
}
