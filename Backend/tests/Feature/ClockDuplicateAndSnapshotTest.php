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

    /**
     * ENMIENDA merge F3: el contrato reconciliado del check_in duplicado es el IDEMPOTENTE de la
     * línea del Reloj (R63): con turno ABIERTO, un 2º check_in devuelve 200 con `duplicate: true`
     * y NO crea fila — la cola offline y los retries de red no deben recibir un error por un
     * duplicado benigno. La garantía de fondo es la misma que este test protegía: UNA sola fila.
     * (El turno PARTIDO check_in→check_out→check_in sigue siendo legítimo; ver el test de abajo.)
     */
    public function test_second_check_in_same_day_is_idempotent(): void
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

        $response->assertStatus(200);
        $response->assertJsonFragment(['duplicate' => true]);

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

    /**
     * ENMIENDA merge F3: el índice UNIQUE(user,date,type) se RETIRÓ (migración
     * 2026_07_24_120000) porque prohibía el turno PARTIDO y las pausas múltiples — flujos
     * legítimos de la línea del Reloj que la nómina soporta (PayrollSplitShiftTest). Este test
     * fija el contrato positivo que motivó el retiro: check_in→check_out→check_in el mismo día
     * produce DOS check_ins válidos, y el guard de estado sigue rechazando el duplicado real
     * (un 2º check_in con turno abierto no crea fila; ver test de arriba).
     */
    public function test_split_shift_allows_second_check_in_after_check_out(): void
    {
        $user = $this->makeEmployee();

        // El tenant 1 viene sembrado con require_closing_checklist=true (create_store_opening_tables);
        // aquí se apaga porque este test prueba la SECUENCIA del turno partido, no el checklist.
        DB::table('store_opening_settings')->where('tenant_id', 1)->update(['require_closing_checklist' => false]);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '09:00:00',
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_out',
            'time' => '13:00:00',
        ])->assertStatus(200);

        $this->actingAs($user)->postJson('/api/v1/clock/punch', [
            'user_id' => $user->id,
            'type' => 'check_in',
            'time' => '15:00:00',
        ])->assertStatus(200);

        $this->assertSame(2, DB::table('time_entries')
            ->where('user_id', $user->id)->where('type', 'check_in')->count());
    }
}
