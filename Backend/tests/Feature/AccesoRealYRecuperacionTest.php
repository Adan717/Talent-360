<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SocialIdentity;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AccesoRealYRecuperacionTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $tenant = Tenant::create(['name' => 'Acceso QA', 'subdomain' => 'accesoqa', 'plan' => 'enterprise', 'is_active' => true]);
        return User::create(['tenant_id' => $tenant->id, 'name' => 'Prueba', 'email' => 'qa@gmail.com', 'password' => Hash::make('Original!2026'), 'role' => 'admin', 'is_active' => true]);
    }

    use \Tests\Concerns\SignsSocialCredentials;

    public function test_google_firmado_emite_cookie_y_no_se_puede_repetir(): void
    {
        $user = $this->user();
        $credential = $this->credential();
        $this->withCredentials()->withUnencryptedCookie(SocialIdentity::COOKIE, 'browser-test')->postJson('/api/v1/login/social', $credential)
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertCookie('talent_auth_token');
        $this->assertSame('real-subject', $user->fresh()->google_id);
        $this->postJson('/api/v1/login/social', $credential)->assertStatus(401);
    }

    public function test_rechaza_audiencia_emisor_nonce_vencimiento_y_correo_invalidos(): void
    {
        $this->user();
        foreach ([['aud' => 'other-app'], ['iss' => 'https://evil.test'], ['nonce' => 'other'], ['exp' => time() - 30], ['email_verified' => false]] as $changes) {
            Cache::forget('social-jwks:google');
            $this->withCredentials()->withUnencryptedCookie(SocialIdentity::COOKIE, 'browser-test')->postJson('/api/v1/login/social', $this->credential($changes))->assertStatus(401)->assertJsonMissingPath('token');
        }
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_no_acepta_otro_navegador_ni_firma_falsa(): void
    {
        $data = $this->credential();
        $this->withCredentials()->withUnencryptedCookie(SocialIdentity::COOKIE, 'other-browser')->postJson('/api/v1/login/social', $data)->assertStatus(401);
        $data['id_token'] = substr($data['id_token'], 0, strrpos($data['id_token'], '.') + 1).'false-signature';
        $this->withCredentials()->withUnencryptedCookie(SocialIdentity::COOKIE, 'browser-test')->postJson('/api/v1/login/social', $data)->assertStatus(401);
    }

    public function test_no_resucita_usuario_archivado_ni_reasigna_vinculo(): void
    {
        $user = $this->user();
        $user->update(['google_id' => 'previous-sub']);
        $this->withCredentials()->withUnencryptedCookie(SocialIdentity::COOKIE, 'browser-test')->postJson('/api/v1/login/social', $this->credential())->assertStatus(409);
        $user->update(['google_id' => 'real-subject']); $user->delete();
        Cache::forget('social-jwks:google');
        $this->postJson('/api/v1/login/social', $this->credential())->assertStatus(403);
        $this->assertNotNull($user->fresh()->deleted_at);
    }

    public function test_no_entra_empresa_suspendida_y_doble_factor_no_es_simulado(): void
    {
        $user = $this->user(); $user->tenant->forceFill(['is_active' => false])->save();
        $this->withCredentials()->withUnencryptedCookie(SocialIdentity::COOKIE, 'browser-test')->postJson('/api/v1/login/social', $this->credential())->assertStatus(403);
        $user->tenant->forceFill(['is_active' => true])->save();
        DB::table('users')->where('id', $user->id)->update(['two_factor_enabled' => true]);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Original!2026'])->assertStatus(503)->assertJsonMissingPath('token');
    }

    public function test_reset_completo_enlace_unico_y_expulsion_de_sesiones(): void
    {
        Mail::fake(); $user = $this->user(); $user->createToken('previous');
        $response = $this->postJson('/api/v1/forgot-password', ['email' => '  QA@GMAIL.COM  '])->assertOk();
        $response->assertJsonMissingPath('token');
        $mail = Mail::queued(PasswordResetMail::class)->first();
        $this->assertNotNull($mail);
        parse_str(parse_url($mail->resetUrl, PHP_URL_QUERY), $parameters);
        $this->assertNotEquals($parameters['token'], DB::table('password_reset_tokens')->value('token'));
        $payload = [...$parameters, 'password' => 'Renovada!2026'];
        $this->postJson('/api/v1/reset-password', $payload)->assertOk();
        $this->assertSame(0, $user->tokens()->count());
        $this->postJson('/api/v1/reset-password', $payload)->assertStatus(422);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Original!2026'])->assertStatus(401);
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'Renovada!2026'])->assertOk();
    }

    public function test_reset_vencido_y_correo_inexistente(): void
    {
        Mail::fake(); $user = $this->user();
        $a = $this->postJson('/api/v1/forgot-password', ['email' => $user->email])->json();
        $this->postJson('/api/v1/forgot-password', ['email' => 'unknown@example.test'])->assertExactJson($a);
        $mail = Mail::queued(PasswordResetMail::class)->first();
        parse_str(parse_url($mail->resetUrl, PHP_URL_QUERY), $parameters);
        DB::table('password_reset_tokens')->update(['created_at' => now()->subMinutes(61)]);
        $this->postJson('/api/v1/reset-password', [...$parameters, 'password' => 'Nueva!2026'])->assertStatus(422);
    }

    public function test_archivo_firmado_de_otra_empresa_no_se_importa(): void
    {
        $admin = $this->user();
        $file = $this->actingAs($admin)->getJson('/api/v1/tenant/backup/export')->assertOk()->json();
        $tenant = Tenant::create(['name' => 'Otro', 'subdomain' => 'other-backup', 'plan' => 'enterprise', 'is_active' => true]);
        $other = User::create(['tenant_id' => $tenant->id, 'name' => 'Otro', 'email' => 'other@example.test', 'password' => bcrypt('secret'), 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($other)->postJson('/api/v1/tenant/backup/import', ['backup_json' => json_encode($file)])->assertStatus(403);
        $this->assertSame($admin->tenant_id, $admin->fresh()->tenant_id);
    }

    public function test_apple_valida_firma_y_canjea_codigo_antes_de_dar_sesion(): void
    {
        $user = $this->user();
        $credential = $this->credential([], 'apple');
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($ec, $private);
        $path = tempnam(sys_get_temp_dir(), 'apple-test-');
        file_put_contents($path, $private);
        try {
            config(['services.apple.team_id' => 'TEAM', 'services.apple.key_id' => 'KEY', 'services.apple.private_key_path' => $path, 'services.apple.redirect_uri' => 'https://talent360.com.mx/login']);
            Http::fake(['appleid.apple.com/auth/token' => Http::response(['id_token' => $credential['id_token']])]);
            $this->withCredentials()->withUnencryptedCookie(SocialIdentity::COOKIE, 'browser-test')
                ->postJson('/api/v1/login/social', [...$credential, 'code' => 'single-use-code'])
                ->assertOk()->assertJsonPath('user.id', $user->id)->assertCookie('talent_auth_token');
            Http::assertSent(fn ($request) => $request->url() === 'https://appleid.apple.com/auth/token'
                && $request['grant_type'] === 'authorization_code' && $request['code'] === 'single-use-code'
                && count(explode('.', $request['client_secret'])) === 3);
            $this->assertSame('real-subject', $user->fresh()->apple_id);
        } finally { unlink($path); }
    }

    public function test_apple_no_canjeado_no_entra(): void
    {
        $this->user();
        $credential = $this->credential([], 'apple');
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($ec, $private);
        $path = tempnam(sys_get_temp_dir(), 'apple-test-'); file_put_contents($path, $private);
        try {
            config(['services.apple.team_id' => 'TEAM', 'services.apple.key_id' => 'KEY', 'services.apple.private_key_path' => $path, 'services.apple.redirect_uri' => 'https://talent360.com.mx/login']);
            Http::fake(['appleid.apple.com/auth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
            $this->withCredentials()->withUnencryptedCookie(SocialIdentity::COOKIE, 'browser-test')
                ->postJson('/api/v1/login/social', [...$credential, 'code' => 'expired-code'])->assertStatus(401)->assertJsonMissingPath('token');
            $this->assertSame(0, DB::table('personal_access_tokens')->count());
        } finally { unlink($path); }
    }

    public function test_registro_no_roba_preregistro_ni_revive_cuenta_archivada(): void
    {
        $user = $this->user();
        $user->update(['tenant_id' => null]);
        foreach ([false, true] as $archived) {
            if ($archived) $user->delete();
            $this->postJson('/api/v1/register', ['name' => 'Intruso', 'email' => $user->email, 'password' => 'Atacante!2026'])
                ->assertStatus(409)->assertJsonMissingPath('token');
            $this->assertTrue(Hash::check('Original!2026', $user->fresh()->password));
        }
        $this->assertNotNull($user->fresh()->deleted_at);
    }

    public function test_google_no_crea_cuenta_nueva_con_correo_de_tercero_no_autoritativo(): void
    {
        $credential = $this->credential(['email' => 'externo@example.test']);
        $this->withCredentials()->withUnencryptedCookie(SocialIdentity::COOKIE, 'browser-test')
            ->postJson('/api/v1/login/social', $credential)->assertStatus(409)->assertJsonMissingPath('token');
        $this->assertDatabaseMissing('users', ['email' => 'externo@example.test']);
    }
}
