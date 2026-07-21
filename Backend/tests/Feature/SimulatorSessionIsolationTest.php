<?php

namespace Tests\Feature;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SimulatorSessionIsolationTest extends TestCase
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

    private function makeUser(string $role = 'admin'): User
    {
        $user = User::factory()->create(['role' => $role]);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);
        $user->refresh();

        return $user;
    }

    public function test_active_session_is_created_on_first_call_and_reused_after(): void
    {
        $admin = $this->makeUser();

        $first = $this->actingAs($admin)->getJson('/api/v1/matrix/session/active');
        $first->assertStatus(200);
        $firstId = $first->json('session_id');

        $second = $this->actingAs($admin)->getJson('/api/v1/matrix/session/active');
        $second->assertStatus(200);

        $this->assertEquals($firstId, $second->json('session_id'));
        $this->assertDatabaseCount('simulator_sessions', 1);
    }

    public function test_starting_new_session_closes_old_one_and_advances_simulated_date(): void
    {
        $admin = $this->makeUser();

        $first = $this->actingAs($admin)->getJson('/api/v1/matrix/session/active');
        $firstDate = $first->json('simulated_date');

        $new = $this->actingAs($admin)->postJson('/api/v1/matrix/session/new');
        $new->assertStatus(200);

        $this->assertNotEquals($first->json('session_id'), $new->json('session_id'));
        $this->assertEquals(
            \Carbon\Carbon::parse($firstDate)->addDay()->format('Y-m-d'),
            $new->json('simulated_date')
        );

        $this->assertDatabaseHas('simulator_sessions', [
            'id' => $first->json('session_id'),
            'status' => 'closed',
        ]);
        $this->assertDatabaseHas('simulator_sessions', [
            'id' => $new->json('session_id'),
            'status' => 'active',
        ]);
    }

    public function test_simulator_punch_is_tagged_with_active_session_and_simulated_date(): void
    {
        $admin = $this->makeUser('admin');

        $sessionResponse = $this->actingAs($admin)->getJson('/api/v1/matrix/session/active');
        $simulatedDate = $sessionResponse->json('simulated_date');
        $sessionId = $sessionResponse->json('session_id');

        $punchResponse = $this->actingAs($admin)->postJson('/api/v1/clock/punch', [
            'user_id' => $admin->id,
            'type' => 'check_in',
            'time' => '09:00:00',
            'details' => ['is_simulator' => true],
        ]);

        $punchResponse->assertStatus(200);

        $this->assertDatabaseHas('time_entries', [
            'user_id' => $admin->id,
            'type' => 'check_in',
            'date' => $simulatedDate,
            'simulation_session_id' => $sessionId,
        ]);
    }

    public function test_successive_simulator_sessions_never_collide_with_unique_constraint(): void
    {
        $admin = $this->makeUser('admin');

        // Sesión 1: check_in simulado.
        $this->actingAs($admin)->postJson('/api/v1/clock/punch', [
            'user_id' => $admin->id,
            'type' => 'check_in',
            'time' => '09:00:00',
            'details' => ['is_simulator' => true],
        ])->assertStatus(200);

        // Avanza a una nueva sesión (nuevo simulated_date).
        $this->actingAs($admin)->postJson('/api/v1/matrix/session/new')->assertStatus(200);

        // Sesión 2: mismo type, mismo usuario — no debe chocar porque cambió la fecha simulada.
        $response = $this->actingAs($admin)->postJson('/api/v1/clock/punch', [
            'user_id' => $admin->id,
            'type' => 'check_in',
            'time' => '09:00:00',
            'details' => ['is_simulator' => true],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseCount('time_entries', 2);
    }

    public function test_default_time_entry_queries_exclude_simulated_rows(): void
    {
        $admin = $this->makeUser('empleado');

        // Fichaje real.
        DB::table('time_entries')->insert([
            'user_id' => $admin->id,
            'tenant_id' => 1,
            'date' => '2026-07-21',
            'type' => 'check_in',
            'time' => '09:00:00',
            'is_late' => false,
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Fichaje simulado (sesión de prueba).
        $sessionId = DB::table('simulator_sessions')->insertGetId([
            'tenant_id' => 1,
            'simulated_date' => '2026-08-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('time_entries')->insert([
            'user_id' => $admin->id,
            'tenant_id' => 1,
            'date' => '2026-08-01',
            'type' => 'check_in',
            'time' => '09:00:00',
            'is_late' => false,
            'late_minutes' => 0,
            'simulation_session_id' => $sessionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // El scope global excluye por defecto la fila simulada.
        $this->assertEquals(1, TimeEntry::where('user_id', $admin->id)->count());

        // Bypass explícito ve ambas.
        $this->assertEquals(
            2,
            TimeEntry::withoutGlobalScope(\App\Scopes\ExcludeSimulationScope::class)
                ->where('user_id', $admin->id)
                ->count()
        );
    }

    public function test_purge_deletes_only_simulated_rows_never_real_ones(): void
    {
        $admin = $this->makeUser();

        DB::table('time_entries')->insert([
            'user_id' => $admin->id,
            'tenant_id' => 1,
            'date' => '2026-07-21',
            'type' => 'check_in',
            'time' => '09:00:00',
            'is_late' => false,
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionResponse = $this->actingAs($admin)->getJson('/api/v1/matrix/session/active');
        $sessionId = $sessionResponse->json('session_id');

        DB::table('time_entries')->insert([
            'user_id' => $admin->id,
            'tenant_id' => 1,
            'date' => $sessionResponse->json('simulated_date'),
            'type' => 'meal_start',
            'time' => '13:00:00',
            'is_late' => false,
            'late_minutes' => 0,
            'simulation_session_id' => $sessionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('time_entries', 2);

        $purgeResponse = $this->actingAs($admin)->postJson('/api/v1/sync/reset');
        $purgeResponse->assertStatus(200);
        $purgeResponse->assertJson(['success' => true, 'purged_sessions' => 1]);

        $this->assertDatabaseCount('time_entries', 1);
        $this->assertDatabaseHas('time_entries', ['type' => 'check_in', 'simulation_session_id' => null]);
    }

    public function test_payroll_report_excludes_simulated_data_by_default_and_includes_it_when_requested(): void
    {
        $admin = $this->makeUser();

        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'user_id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'base_salary' => 3000,
            'is_active_employee' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Retardo real hoy.
        DB::table('time_entries')->insert([
            'user_id' => $admin->id,
            'tenant_id' => 1,
            'date' => now()->format('Y-m-d'),
            'type' => 'check_in',
            'time' => '10:00:00',
            'is_late' => true,
            'late_minutes' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionId = DB::table('simulator_sessions')->insertGetId([
            'tenant_id' => 1,
            'simulated_date' => now()->addDays(30)->format('Y-m-d'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Retardo simulado, muy en el futuro (fuera del rango real de nómina).
        DB::table('time_entries')->insert([
            'user_id' => $admin->id,
            'tenant_id' => 1,
            'date' => now()->addDays(30)->format('Y-m-d'),
            'type' => 'check_in',
            'time' => '11:00:00',
            'is_late' => true,
            'late_minutes' => 90,
            'simulation_session_id' => $sessionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $employee = \App\Models\Employee::find($employeeId);
        $clockService = app(\App\Services\ClockService::class);

        $startDate = now()->startOfMonth()->format('Y-m-d');
        $endDate = now()->addDays(45)->format('Y-m-d');

        $realPayroll = $clockService->calculatePayrollForEmployee($employee, $startDate, $endDate);
        $this->assertEquals(1, $realPayroll['incidents']['lates']);

        $simulatedPayroll = $clockService->calculatePayrollForEmployee($employee, $startDate, $endDate, $sessionId);
        $this->assertEquals(1, $simulatedPayroll['incidents']['lates']);
        $this->assertEquals(90, $simulatedPayroll['incidents']['late_minutes']);
    }
}
