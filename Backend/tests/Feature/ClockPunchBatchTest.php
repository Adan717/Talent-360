<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClockPunchBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1,
            'name' => 'Default Tenant',
            'subdomain' => 'default',
            'plan' => 'free',
            'max_users' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeEmployee(): User
    {
        $user = User::factory()->create([
            'role' => 'empleado',
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    public function test_offline_secret_is_generated_and_stable_across_calls(): void
    {
        $user = $this->makeEmployee();

        $first = $this->actingAs($user)->getJson('/api/v1/clock/offline-secret');
        $first->assertStatus(200);
        $first->assertJsonStructure(['success', 'secret', 'issued_at']);

        $second = $this->actingAs($user)->getJson('/api/v1/clock/offline-secret');
        $second->assertStatus(200);

        $this->assertEquals($first->json('secret'), $second->json('secret'));
        $this->assertDatabaseCount('tenant_offline_secrets', 1);
    }

    public function test_punch_batch_accepts_valid_signatures_and_persists_offline_time(): void
    {
        $user = $this->makeEmployee();

        $secretResponse = $this->actingAs($user)->getJson('/api/v1/clock/offline-secret');
        $secret = $secretResponse->json('secret');

        // Fecha relativa (ayer) para no caer en la ventana anti-backdating de MAX_AGE_DAYS.
        $checkInTime = '08:05:00';
        $checkInTimestamp = now('-06:00')->subDay()->format('Y-m-d') . 'T08:05:00-06:00';
        $checkInStamp = hash_hmac('sha256', "{$user->id}|check_in|{$checkInTime}|{$checkInTimestamp}", $secret);

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => json_encode('simulated'),
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [
                [
                    'user_id' => $user->id,
                    'type' => 'check_in',
                    'time' => $checkInTime,
                    'details' => ['note' => 'Sin luz en sucursal'],
                    'client_timestamp' => $checkInTimestamp,
                    'offline_stamp' => $checkInStamp,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'processed' => 1, 'rejected' => 0]);

        $this->assertDatabaseHas('time_entries', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => $checkInTime,
        ]);
    }

    public function test_punch_batch_rejects_invalid_signature_without_aborting_batch(): void
    {
        $user = $this->makeEmployee();

        $secretResponse = $this->actingAs($user)->getJson('/api/v1/clock/offline-secret');
        $secret = $secretResponse->json('secret');

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => json_encode('simulated'),
        ]);

        // Fecha relativa (ayer) para no caer en la ventana anti-backdating de MAX_AGE_DAYS.
        $validTime = '08:05:00';
        $validTimestamp = now('-06:00')->subDay()->format('Y-m-d') . 'T08:05:00-06:00';
        $validStamp = hash_hmac('sha256', "{$user->id}|check_in|{$validTime}|{$validTimestamp}", $secret);

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch-batch', [
            'punches' => [
                [
                    'user_id' => $user->id,
                    'type' => 'check_in',
                    'time' => $validTime,
                    'client_timestamp' => $validTimestamp,
                    'offline_stamp' => $validStamp,
                ],
                [
                    'user_id' => $user->id,
                    'type' => 'meal_start',
                    'time' => '13:00:00',
                    'client_timestamp' => '2026-07-20T13:00:00-06:00',
                    'offline_stamp' => 'firma-invalida-manipulada',
                ],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'processed' => 1, 'rejected' => 1]);

        $results = $response->json('results');
        $this->assertEquals(0, $results[0]['index']);
        $this->assertTrue($results[0]['success']);
        $this->assertEquals(1, $results[1]['index']);
        $this->assertFalse($results[1]['success']);
        $this->assertEquals('invalid_signature', $results[1]['reason']);

        $this->assertDatabaseCount('time_entries', 1);
    }
}
