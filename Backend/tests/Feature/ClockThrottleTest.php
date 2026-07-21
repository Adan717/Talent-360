<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClockThrottleTest extends TestCase
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

    private function makeUser(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    public function test_clock_punch_is_throttled_after_20_requests_per_minute(): void
    {
        $user = $this->makeUser();

        for ($i = 1; $i <= 20; $i++) {
            $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id,
                'type' => 'waiting',
            ]);
            $this->assertNotEquals(429, $response->status(), "La petición #$i no debería estar limitada todavía.");
        }

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'waiting',
        ]);

        $response->assertStatus(429);
    }

    public function test_emergency_open_is_throttled_after_5_requests_per_minute(): void
    {
        $user = $this->makeUser();

        for ($i = 1; $i <= 5; $i++) {
            $response = $this->actingAs($user)->postJson('/api/v1/clock/emergency-open', [
                'requester_id' => $user->id,
                'witness_1_id' => $user->id,
                'witness_1_pin' => '0000',
                'witness_2_id' => $user->id,
                'witness_2_pin' => '0000',
            ]);
            $this->assertNotEquals(429, $response->status(), "La petición #$i no debería estar limitada todavía.");
        }

        $response = $this->actingAs($user)->postJson('/api/v1/clock/emergency-open', [
            'requester_id' => $user->id,
            'witness_1_id' => $user->id,
            'witness_1_pin' => '0000',
            'witness_2_id' => $user->id,
            'witness_2_pin' => '0000',
        ]);

        $response->assertStatus(429);
    }
}
