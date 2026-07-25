<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthCookieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'T', 'subdomain' => 't1', 'plan' => 'pro',
            'max_users' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_login_sets_httponly_auth_cookie(): void
    {
        $user = User::factory()->create(['email' => 'coo@x.com', 'password' => Hash::make('secret123'), 'role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        $response = $this->postJson('/api/v1/login', ['email' => 'coo@x.com', 'password' => 'secret123']);

        $response->assertStatus(200);
        $response->assertCookie('talent_auth_token');
        // El token del cuerpo y el de la cookie deben ser el mismo.
        $this->assertEquals($response->json('token'), $response->getCookie('talent_auth_token', false)->getValue());
    }

    public function test_middleware_copies_cookie_token_into_the_authorization_header(): void
    {
        // El harness HTTP de este entorno no adjunta cookies al request saliente, así
        // que se prueba el middleware §43 directamente: una petición con la cookie del
        // token y SIN header Authorization debe salir con el header ya poblado, que es
        // exactamente lo que Sanctum necesita para autenticar.
        $middleware = new \App\Http\Middleware\AuthTokenFromCookie();

        $request = \Illuminate\Http\Request::create('/api/v1/me', 'GET', [], [
            'talent_auth_token' => '123|token-de-prueba',
        ]);
        $this->assertNull($request->bearerToken());

        $passed = null;
        $middleware->handle($request, function ($req) use (&$passed) {
            $passed = $req;
            return response('ok');
        });

        $this->assertSame('Bearer 123|token-de-prueba', $passed->headers->get('Authorization'));
        $this->assertSame('123|token-de-prueba', $passed->bearerToken());
    }

    public function test_middleware_does_not_override_an_existing_bearer_header(): void
    {
        $middleware = new \App\Http\Middleware\AuthTokenFromCookie();

        $request = \Illuminate\Http\Request::create('/api/v1/me', 'GET', [], [
            'talent_auth_token' => 'cookie-token',
        ]);
        $request->headers->set('Authorization', 'Bearer header-token');

        $passed = null;
        $middleware->handle($request, function ($req) use (&$passed) {
            $passed = $req;
            return response('ok');
        });

        // El header explícito manda; la cookie no lo pisa.
        $this->assertSame('header-token', $passed->bearerToken());
    }

    public function test_logout_expires_the_auth_cookie(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/logout');

        $response->assertStatus(200);
        // La cookie queda expirada (Laravel la marca con expiración en el pasado).
        $response->assertCookieExpired('talent_auth_token');
    }
}
