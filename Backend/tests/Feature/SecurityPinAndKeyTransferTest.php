<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityPinAndKeyTransferTest extends TestCase
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

    private function makeEmployee(string $password = 'password123', string $portadorLlaves = 'ninguno'): User
    {
        $user = User::factory()->create([
            'role' => 'empleado',
            'password' => Hash::make($password),
        ]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'portadorLlaves' => $portadorLlaves,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_user_can_set_security_pin_with_correct_password(): void
    {
        $user = $this->makeEmployee('password123');

        $response = $this->actingAs($user)->putJson('/api/v1/me/security-pin', [
            'current_password' => 'password123',
            'pin' => '4821',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $employee = DB::table('employees')->where('user_id', $user->id)->first();
        $this->assertNotNull($employee->security_pin);
        $this->assertTrue(Hash::check('4821', $employee->security_pin));
    }

    public function test_security_pin_update_rejects_wrong_password(): void
    {
        $user = $this->makeEmployee('password123');

        $response = $this->actingAs($user)->putJson('/api/v1/me/security-pin', [
            'current_password' => 'incorrecta',
            'pin' => '4821',
        ]);

        $response->assertStatus(422);
    }

    public function test_security_pin_rejects_non_numeric_value(): void
    {
        $user = $this->makeEmployee('password123');

        $response = $this->actingAs($user)->putJson('/api/v1/me/security-pin', [
            'current_password' => 'password123',
            'pin' => 'abcd',
        ]);

        $response->assertStatus(422);
    }

    public function test_keyholder_can_create_transfer_and_receiver_gains_portador_llaves(): void
    {
        $sender = $this->makeEmployee('password123', 'Principal');
        $receiver = $this->makeEmployee('password123', 'ninguno');

        $storeResponse = $this->actingAs($sender)->postJson('/api/v1/key-transfers', [
            'receiver_id' => $receiver->id,
        ]);
        $storeResponse->assertStatus(201);

        $transferId = $storeResponse->json('transfer.id');

        $respondResponse = $this->actingAs($receiver)->postJson("/api/v1/key-transfers/{$transferId}/respond", [
            'status' => 'accepted',
        ]);

        $respondResponse->assertStatus(200);

        $this->assertDatabaseHas('employees', [
            'user_id' => $receiver->id,
            'portadorLlaves' => 'Principal',
        ]);
        $this->assertDatabaseHas('employees', [
            'user_id' => $sender->id,
            'portadorLlaves' => 'Ninguno',
        ]);
    }

    public function test_non_keyholder_cannot_create_transfer(): void
    {
        $sender = $this->makeEmployee('password123', 'ninguno');
        $receiver = $this->makeEmployee('password123', 'ninguno');

        $response = $this->actingAs($sender)->postJson('/api/v1/key-transfers', [
            'receiver_id' => $receiver->id,
        ]);

        $response->assertStatus(403);
    }
}
