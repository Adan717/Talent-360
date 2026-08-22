<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClockAlarmClosingChecklistTest extends TestCase
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

    private function makeEmployee(): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    /**
     * Portador de llaves: el checklist de cierre sólo frena a quien CIERRA (2026-08-22).
     * Antes frenaba la salida de toda la plantilla; ver ChecklistDeCierreSoloFrenaAPortadoresTest.
     */
    private function makeKeyholder(): User
    {
        $user = $this->makeEmployee();
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1, 'user_id' => $user->id, 'name' => $user->name, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'salary' => 3000, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('store_opening_assignments')->insert([
            'tenant_id' => 1, 'company_id' => 1, 'store_id' => 1, 'employee_id' => $employeeId,
            'priority_order' => 1, 'can_open_store' => true, 'has_keys' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_pre_shift_alarm_saves_valid_value(): void
    {
        $user = $this->makeEmployee();

        $response = $this->actingAs($user)->putJson('/api/v1/me/pre-shift-alarm', ['minutes' => 45]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'pre_shift_alarm_minutes' => 45]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'pre_shift_alarm_minutes' => 45]);
    }

    public function test_pre_shift_alarm_can_be_cleared_with_null(): void
    {
        $user = $this->makeEmployee();
        $this->actingAs($user)->putJson('/api/v1/me/pre-shift-alarm', ['minutes' => 30]);

        $response = $this->actingAs($user)->putJson('/api/v1/me/pre-shift-alarm', ['minutes' => null]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true, 'pre_shift_alarm_minutes' => null]);
    }

    public function test_pre_shift_alarm_rejects_invalid_value(): void
    {
        $user = $this->makeEmployee();

        $response = $this->actingAs($user)->putJson('/api/v1/me/pre-shift-alarm', ['minutes' => 20]);

        $response->assertStatus(422);
    }

    public function test_check_out_is_blocked_without_completed_closing_checklist(): void
    {
        $user = $this->makeKeyholder();

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => json_encode('simulated'),
        ]);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ])->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_out',
            'time' => '18:00:00',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['success' => false, 'message' => 'Completa el checklist de cierre antes de registrar salida.']);
    }

    public function test_check_out_succeeds_after_closing_checklist_completed(): void
    {
        $user = $this->makeKeyholder();

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => json_encode('simulated'),
        ]);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ])->assertStatus(200);

        $checklistResponse = $this->actingAs($user)->postJson('/api/v1/store-opening/closing-checklist', [
            'user_id' => $user->id,
            'checks' => [
                'lights_off' => true,
                'safe_secured' => true,
                'alarm_activated' => true,
            ],
        ]);
        $checklistResponse->assertStatus(200);
        $checklistResponse->assertJson(['success' => true, 'completed' => true]);

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_out',
            'time' => '18:00:00',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('time_entries', [
            'user_id' => $user->id,
            'type' => 'check_out',
        ]);
    }

    public function test_incomplete_closing_checklist_does_not_unblock_check_out(): void
    {
        $user = $this->makeKeyholder();

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => json_encode('simulated'),
        ]);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ])->assertStatus(200);

        $checklistResponse = $this->actingAs($user)->postJson('/api/v1/store-opening/closing-checklist', [
            'user_id' => $user->id,
            'checks' => [
                'lights_off' => true,
                'safe_secured' => false,
                'alarm_activated' => true,
            ],
        ]);
        $checklistResponse->assertJson(['success' => true, 'completed' => false]);

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_out',
            'time' => '18:00:00',
        ]);

        $response->assertStatus(400);
    }
}
