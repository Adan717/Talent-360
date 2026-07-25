<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La custodia de llaves vive en `employees.portadorLlaves` (la columna
 * `users.portadorLlaves` fue eliminada por la migración que desacopló users y
 * employees). KeyTransferController opera sobre el employee vinculado del usuario.
 */
class KeyTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedTenant(1, 'Tenant Uno');
        $this->seedTenant(2, 'Tenant Dos');
    }

    private function seedTenant(int $id, string $name): void
    {
        DB::table('tenants')->insertOrIgnore([
            'id' => $id,
            'name' => $name,
            'subdomain' => 'tenant' . $id,
            'public_slug' => 'tenant' . $id,
            'plan' => 'enterprise',
            'max_users' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Crea un usuario con su employee vinculado y el portadorLlaves indicado.
     * La feature de transferencia lee/escribe employees.portadorLlaves.
     */
    private function makeUserWithEmployee(int $tenantId, string $role, ?string $portadorLlaves = 'ninguno'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $tenantId]);
        DB::table('employees')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => 'emp' . $user->id . '@t.local',
            'is_active_employee' => true,
            'portadorLlaves' => $portadorLlaves,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Re-fetch para que la instancia en memoria (usada por actingAs) refleje la BD.
        return User::withoutGlobalScopes()->find($user->id);
    }

    public function test_key_transfer_happy_path(): void
    {
        $sender = $this->makeUserWithEmployee(1, 'supervisor', 'ambos');
        $receiver = $this->makeUserWithEmployee(1, 'supervisor', 'ninguno');

        $create = $this->actingAs($sender)->postJson('/api/v1/key-transfers', [
            'receiver_id' => $receiver->id,
            'notes' => 'Cubre mi turno de apertura',
        ]);
        $create->assertStatus(201);
        $transferId = $create->json('transfer.id');

        $pending = $this->actingAs($receiver)->getJson('/api/v1/key-transfers/pending');
        $pending->assertStatus(200)->assertJsonFragment(['sender_id' => $sender->id]);

        $respond = $this->actingAs($receiver)->postJson("/api/v1/key-transfers/{$transferId}/respond", [
            'status' => 'accepted',
        ]);
        $respond->assertStatus(200);

        // La custodia se mueve en employees.portadorLlaves (no en users).
        $this->assertDatabaseHas('employees', ['user_id' => $receiver->id, 'portadorLlaves' => 'ambos']);
        $this->assertDatabaseHas('employees', ['user_id' => $sender->id, 'portadorLlaves' => 'ninguno']);
    }

    public function test_cannot_transfer_when_sender_is_not_key_holder(): void
    {
        $sender = $this->makeUserWithEmployee(1, 'empleado', 'ninguno');
        $receiver = $this->makeUserWithEmployee(1, 'supervisor', 'ninguno');

        $response = $this->actingAs($sender)->postJson('/api/v1/key-transfers', [
            'receiver_id' => $receiver->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_pending_returns_ok(): void
    {
        $sender = $this->makeUserWithEmployee(1, 'supervisor', 'ambos');
        $receiver = $this->makeUserWithEmployee(1, 'supervisor', 'ninguno');

        $this->actingAs($sender)->postJson('/api/v1/key-transfers', [
            'receiver_id' => $receiver->id,
        ])->assertStatus(201);

        // pending() antes devolvía 500 (seleccionaba users.portadorLlaves, columna eliminada).
        $this->actingAs($receiver)->getJson('/api/v1/key-transfers/pending')
            ->assertStatus(200)
            ->assertJsonFragment(['sender_id' => $sender->id]);
    }

    public function test_cannot_transfer_to_self(): void
    {
        $sender = $this->makeUserWithEmployee(1, 'supervisor', 'ambos');

        $response = $this->actingAs($sender)->postJson('/api/v1/key-transfers', [
            'receiver_id' => $sender->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonFragment(['error' => 'No puedes transferirte las llaves a ti mismo.']);
    }

    /**
     * Fuga cross-tenant (fix de Ronda 1): la validación de receiver_id acotada por
     * tenant ocurre antes del resto y rechaza a un receptor de otra empresa.
     */
    public function test_cannot_transfer_to_receiver_in_other_tenant(): void
    {
        $sender = $this->makeUserWithEmployee(1, 'supervisor', 'ambos');
        $foreignReceiver = $this->makeUserWithEmployee(2, 'supervisor', 'ninguno');

        $response = $this->actingAs($sender)->postJson('/api/v1/key-transfers', [
            'receiver_id' => $foreignReceiver->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('key_transfers', ['receiver_id' => $foreignReceiver->id]);
    }

    /**
     * No se puede transferir a un receptor sin expediente de empleado (no podría
     * aceptar y la solicitud quedaría atascada en 'pending'). Se rechaza en store().
     */
    public function test_cannot_transfer_to_receiver_without_employee_record(): void
    {
        $sender = $this->makeUserWithEmployee(1, 'supervisor', 'ambos');
        $receiver = User::factory()->create(['role' => 'admin']);
        DB::table('users')->where('id', $receiver->id)->update(['tenant_id' => 1]);

        $response = $this->actingAs($sender)->postJson('/api/v1/key-transfers', [
            'receiver_id' => $receiver->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('key_transfers', ['receiver_id' => $receiver->id]);
    }
}
