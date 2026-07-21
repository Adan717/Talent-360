<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ClockEmergencyContingencyTest extends TestCase
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

    private function makeEmployee(array $overrides = [], ?string $securityPin = null): User
    {
        $user = User::factory()->create(array_merge(['role' => 'empleado'], $overrides));
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        // store_opening_assignments.employee_id referencia employees.id, no users.id
        // (migración 2026_07_07_192928). Se crea 1:1 en el mismo orden para que ambos ids
        // coincidan, igual que asume el resto del código de StoreOpeningService.
        // security_pin (distinto de pin_code) vive en employees, no en users.
        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'security_pin' => $securityPin ? Hash::make($securityPin) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_emergency_open_succeeds_with_valid_witness_pins(): void
    {
        $requester = $this->makeEmployee();
        $witness1 = $this->makeEmployee([], '1111');
        $witness2 = $this->makeEmployee([], '2222');

        DB::table('store_opening_assignments')->insert([
            'tenant_id' => 1,
            'store_id' => 1,
            'employee_id' => $requester->id,
            'priority_order' => 1,
            'can_open_store' => true,
            'can_close_store' => true,
            'has_keys' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($requester)->postJson('/api/v1/clock/emergency-open', [
            'requester_id' => $requester->id,
            'witness_1_id' => $witness1->id,
            'witness_1_pin' => '1111',
            'witness_2_id' => $witness2->id,
            'witness_2_pin' => '2222',
            'store_id' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertDatabaseHas('store_daily_opening_statuses', [
            'tenant_id' => 1,
            'status' => 'opened',
            'opened_by_employee_id' => $requester->id,
        ]);
        $this->assertDatabaseHas('store_opening_events', [
            'event_type' => 'emergency_open',
            'employee_id' => $requester->id,
        ]);
        $this->assertDatabaseHas('time_entries', [
            'user_id' => $requester->id,
            'type' => 'check_in',
        ]);
    }

    public function test_emergency_open_rejects_incorrect_witness_pin(): void
    {
        $requester = $this->makeEmployee();
        $witness1 = $this->makeEmployee([], '1111');
        $witness2 = $this->makeEmployee([], '2222');

        DB::table('store_opening_assignments')->insert([
            'tenant_id' => 1,
            'store_id' => 1,
            'employee_id' => $requester->id,
            'priority_order' => 1,
            'has_keys' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($requester)->postJson('/api/v1/clock/emergency-open', [
            'requester_id' => $requester->id,
            'witness_1_id' => $witness1->id,
            'witness_1_pin' => '0000',
            'witness_2_id' => $witness2->id,
            'witness_2_pin' => '2222',
            'store_id' => 1,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['success' => false, 'message' => 'PIN de testigo incorrecto.']);

        $this->assertDatabaseMissing('store_daily_opening_statuses', [
            'tenant_id' => 1,
            'status' => 'opened',
        ]);
    }

    public function test_emergency_open_rejects_duplicate_witness(): void
    {
        $requester = $this->makeEmployee();
        $witness1 = $this->makeEmployee([], '1111');

        DB::table('store_opening_assignments')->insert([
            'tenant_id' => 1,
            'store_id' => 1,
            'employee_id' => $requester->id,
            'priority_order' => 1,
            'has_keys' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($requester)->postJson('/api/v1/clock/emergency-open', [
            'requester_id' => $requester->id,
            'witness_1_id' => $witness1->id,
            'witness_1_pin' => '1111',
            'witness_2_id' => $witness1->id,
            'witness_2_pin' => '1111',
            'store_id' => 1,
        ]);

        $response->assertStatus(400);
        $response->assertJson(['success' => false, 'message' => 'Los testigos deben ser dos empleados distintos presentes en sucursal.']);
    }

    public function test_declare_contingency_skips_late_calculation_same_day(): void
    {
        // El empleado no tiene shiftStart configurado en employees, así que processPunch
        // usa el default de 09:00:00 para calcular el retardo.
        $user = $this->makeEmployee();

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => json_encode('simulated'),
        ]);

        $declareResponse = $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', [
            'user_id' => $user->id,
            'reason' => 'no_power',
        ]);

        $declareResponse->assertStatus(200);
        $declareResponse->assertJson(['success' => true]);
        $this->assertDatabaseCount('contingency_declarations', 1);

        // Un check_in muy tarde (11:00) contra un turno de las 08:00 normalmente sería
        // retardo — con la contingencia activa, no debe marcarse is_late.
        $checkInResponse = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '11:00:00',
        ]);

        $checkInResponse->assertStatus(200);

        $this->assertDatabaseHas('time_entries', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'is_late' => false,
        ]);
    }

    public function test_declare_contingency_reuses_open_declaration_same_day(): void
    {
        $user = $this->makeEmployee();

        $first = $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', [
            'user_id' => $user->id,
            'reason' => 'no_internet',
        ]);
        $second = $this->actingAs($user)->postJson('/api/v1/clock/declare-contingency', [
            'user_id' => $user->id,
            'reason' => 'no_internet',
        ]);

        $this->assertEquals($first->json('contingency_id'), $second->json('contingency_id'));
        $this->assertDatabaseCount('contingency_declarations', 1);
    }
}
