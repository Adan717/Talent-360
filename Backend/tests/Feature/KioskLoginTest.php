<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class KioskLoginTest extends TestCase
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
    }

    private function makeEmployee(?string $pin = null): array
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'security_pin' => $pin ? Hash::make($pin) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user' => $user, 'employee_id' => $employeeId];
    }

    public function test_kiosk_login_succeeds_with_correct_pin_and_resolves_tenant_from_employee(): void
    {
        ['user' => $user, 'employee_id' => $employeeId] = $this->makeEmployee('4821');

        $response = $this->postJson('/api/v1/clock/kiosk-login', [
            'employee_id' => $employeeId,
            'pin' => '4821',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertEquals($user->id, $response->json('user.id'));

        // El token emitido debe servir para autenticar una petición normal.
        $token = $response->json('token');
        $me = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/me');
        $me->assertStatus(200);
        $me->assertJson(['user' => ['id' => $user->id]]);
    }

    public function test_kiosk_login_rejects_incorrect_pin_without_revealing_employee_existence(): void
    {
        ['employee_id' => $employeeId] = $this->makeEmployee('4821');

        $wrongPin = $this->postJson('/api/v1/clock/kiosk-login', [
            'employee_id' => $employeeId,
            'pin' => '0000',
        ]);
        $nonexistent = $this->postJson('/api/v1/clock/kiosk-login', [
            'employee_id' => 999999,
            'pin' => '0000',
        ]);

        $wrongPin->assertStatus(422);
        $nonexistent->assertStatus(422);
        $this->assertEquals($wrongPin->json('message'), $nonexistent->json('message'));
    }

    public function test_kiosk_login_rejects_employee_without_configured_pin(): void
    {
        ['employee_id' => $employeeId] = $this->makeEmployee(null);

        $response = $this->postJson('/api/v1/clock/kiosk-login', [
            'employee_id' => $employeeId,
            'pin' => '1234',
        ]);

        $response->assertStatus(422);
    }

    public function test_kiosk_logout_revokes_the_token(): void
    {
        ['user' => $user] = $this->makeEmployee('4821');

        $tokenModel = $user->createToken('kiosk_session');
        $token = $tokenModel->plainTextToken;

        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenModel->accessToken->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/clock/kiosk-logout');
        $response->assertStatus(200);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenModel->accessToken->id]);
    }
}
