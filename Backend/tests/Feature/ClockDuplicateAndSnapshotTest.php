<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClockDuplicateAndSnapshotTest extends TestCase
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

        DB::table('system_settings')->insertOrIgnore([
            'tenant_id' => 1,
            'key' => 'time_mode',
            'value' => json_encode('simulated'),
        ]);
    }

    private function makeEmployee(?int $jobRoleId = null, ?float $baseSalary = null): User
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        DB::table('employees')->insert([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'job_role_id' => $jobRoleId,
            'base_salary' => $baseSalary,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_second_check_in_same_day_is_rejected(): void
    {
        $user = $this->makeEmployee();

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ])->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '09:05:00',
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['success' => false]);
        $this->assertStringContainsString('ya existe un registro', $response->json('message'));

        $this->assertDatabaseCount('time_entries', 1);
    }

    public function test_different_punch_types_same_day_are_all_allowed(): void
    {
        $user = $this->makeEmployee();

        $types = ['check_in', 'meal_start', 'meal_end', 'break_start', 'break_end'];
        foreach ($types as $i => $type) {
            $this->actingAs($user)->postJson('/api/v1/clock/punch', [
                'user_id' => $user->id,
                'type' => $type,
                'time' => sprintf('%02d:00:00', 9 + $i),
            ])->assertStatus(200);
        }

        // check_out exige el checklist de cierre completo para tenants con el módulo
        // store_opening activo (tenant 1 lo tiene siempre desbloqueado).
        $this->actingAs($user)->postJson('/api/v1/store-opening/closing-checklist', [
            'user_id' => $user->id,
            'checks' => ['lights_off' => true, 'safe_secured' => true, 'alarm_activated' => true],
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_out',
            'time' => '18:00:00',
        ])->assertStatus(200);

        $this->assertDatabaseCount('time_entries', count($types) + 1);
    }

    public function test_check_in_persists_immutable_payroll_snapshot(): void
    {
        $jobRoleId = DB::table('job_roles')->insertGetId([
            'name' => 'Cajero Senior',
            'area' => 'Ventas',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = $this->makeEmployee($jobRoleId, 5500.50);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ])->assertStatus(200);

        $entry = DB::table('time_entries')->where('user_id', $user->id)->where('type', 'check_in')->first();

        $this->assertEquals($user->name, $entry->employee_name_at_time);
        $this->assertEquals('Cajero Senior', $entry->job_role_title_at_time);
        $this->assertEquals(5500.50, (float) $entry->base_salary_at_time);
    }

    public function test_database_rejects_duplicate_insert_at_constraint_level(): void
    {
        // Prueba el índice único directamente (sin pasar por processPunch), para
        // confirmar que la ventana de carrera también está cerrada a nivel de BD,
        // no solo por el exists() de la capa de aplicación.
        $user = $this->makeEmployee();

        DB::table('time_entries')->insert([
            'user_id' => $user->id,
            'tenant_id' => 1,
            'date' => '2026-07-21',
            'type' => 'check_in',
            'time' => '09:00:00',
            'is_late' => false,
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('time_entries')->insert([
            'user_id' => $user->id,
            'tenant_id' => 1,
            'date' => '2026-07-21',
            'type' => 'check_in',
            'time' => '09:05:00',
            'is_late' => false,
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
