<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * EL LOGIN SOCIAL NO ENTREGA SESIONES SIN VERIFICAR (candado del 2026-09-06).
 *
 * Hasta hoy `POST /api/v1/login/social` era un bypass de autenticación remoto: la ruta es pública
 * y aceptaba un `provider_id` mandado por el cliente sin comprobar nada. Bastaba enviar el correo
 * de un administrador para que el sistema lo vinculara y devolviera un token de sesión — acceso a
 * su nómina y a sus expedientes SIN CONTRASEÑA. El propio frontend usaba ese camino para Apple,
 * para Samsung y para un "Google de prueba".
 *
 * Estas pruebas fijan que la identidad SÓLO puede salir de un token verificado por el proveedor.
 * La primera es la que reproduce el ataque; si alguien reabre la puerta, se pone roja.
 */
class LoginSocialSinBypassTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $email): User
    {
        $tenant = Tenant::create([
            'name' => 'Empresa QA', 'subdomain' => 'empresaqa', 'plan' => 'enterprise', 'is_active' => true,
        ]);

        return User::create([
            'tenant_id' => $tenant->id, 'name' => 'Admin Real', 'email' => $email,
            'password' => Hash::make('una-contrasena-que-nadie-manda'), 'role' => 'admin',
        ]);
    }

    /** EL ATAQUE: el correo de un admin, un id inventado, sin token. Antes esto daba una sesión. */
    public function test_no_se_puede_entrar_como_un_admin_mandando_su_correo(): void
    {
        $admin = $this->admin('admin@empresaqa.test');

        $r = $this->postJson('/api/v1/login/social', [
            'provider' => 'apple',
            'provider_id' => 'lo-que-se-me-ocurra',
            'email' => 'admin@empresaqa.test',
        ]);

        // No hay sesión, no hay token, y —crucial— la cuenta del admin no quedó vinculada a un id
        // que un atacante controle.
        $this->assertNotEquals(200, $r->status());
        $r->assertJsonMissingPath('token');
        $this->assertNull($admin->fresh()->apple_id);
    }

    /** Google sin token tampoco: el `id_token` es obligatorio. */
    public function test_google_sin_token_es_rechazado(): void
    {
        $this->admin('jefe@empresaqa.test');

        $this->postJson('/api/v1/login/social', [
            'provider' => 'google',
            'provider_id' => 'x',
            'email' => 'jefe@empresaqa.test',
        ])->assertStatus(422); // falta id_token, requerido
    }

    /** Apple y Samsung están cerrados: no hay verificación de su token del lado del servidor. */
    public function test_apple_y_samsung_estan_cerrados(): void
    {
        foreach (['apple', 'samsung'] as $provider) {
            $this->postJson('/api/v1/login/social', [
                'provider' => $provider,
                'id_token' => 'cualquier-cosa',
            ])->assertStatus(501);
        }
    }

    /** Con Google configurado, un token cuya audiencia es OTRA app no entra. */
    public function test_un_token_de_google_de_otra_app_no_entra(): void
    {
        config(['services.google.client_id' => 'la-app-de-talent360.apps.googleusercontent.com']);
        $this->admin('victima@empresaqa.test');

        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'aud' => 'OTRA-app.apps.googleusercontent.com',   // token legítimo, pero de otra app
            'sub' => '10987', 'email' => 'victima@empresaqa.test', 'email_verified' => 'true',
        ], 200)]);

        $this->postJson('/api/v1/login/social', [
            'provider' => 'google', 'id_token' => 'token-valido-de-otra-app',
        ])->assertStatus(401);
    }

    /** Sin Client ID configurado, Google queda cerrado en vez de confiar a ciegas. */
    public function test_google_sin_client_id_configurado_queda_cerrado(): void
    {
        config(['services.google.client_id' => null]);

        $this->postJson('/api/v1/login/social', [
            'provider' => 'google', 'id_token' => 'lo-que-sea',
        ])->assertStatus(501);
    }

    /** El camino legítimo SIGUE funcionando: token verificado, audiencia correcta, correo verificado. */
    public function test_el_google_legitimo_si_entra(): void
    {
        config(['services.google.client_id' => 'talent360.apps.googleusercontent.com']);
        $admin = $this->admin('real@empresaqa.test');

        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'aud' => 'talent360.apps.googleusercontent.com',
            'sub' => 'google-sub-real-123',
            'email' => 'real@empresaqa.test',
            'email_verified' => 'true',
            'name' => 'Admin Real',
        ], 200)]);

        $r = $this->postJson('/api/v1/login/social', [
            'provider' => 'google', 'id_token' => 'token-bueno',
        ]);

        $r->assertStatus(200)->assertJsonPath('message', 'Login exitoso');
        $this->assertNotEmpty($r->json('token'));
        // Y la cuenta quedó vinculada al `sub` que vino DEL TOKEN, no de un id del cuerpo.
        $this->assertSame('google-sub-real-123', $admin->fresh()->google_id);
    }

    /** Un correo de Google sin verificar no sirve para encontrar ni vincular una cuenta. */
    public function test_google_con_correo_sin_verificar_no_entra(): void
    {
        config(['services.google.client_id' => 'talent360.apps.googleusercontent.com']);
        $this->admin('sinverificar@empresaqa.test');

        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'aud' => 'talent360.apps.googleusercontent.com',
            'sub' => 'sub-x', 'email' => 'sinverificar@empresaqa.test', 'email_verified' => 'false',
        ], 200)]);

        $this->postJson('/api/v1/login/social', [
            'provider' => 'google', 'id_token' => 'token-correo-no-verificado',
        ])->assertStatus(401);
    }
}
