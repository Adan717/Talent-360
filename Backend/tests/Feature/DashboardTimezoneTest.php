<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los dashboards (getStats / getMonitorData) leían "hoy" con Carbon::today() (UTC), mientras
 * los ponches se fechan en la tz del tenant. Cerca del anochecer local el where('date')
 * erraba de día → el monitor mostraba a colaboradores activos como "offline" y las stats del
 * día quedaban en 0. Se alinean a la tz del tenant (como el resto del módulo desde la R9).
 */
class DashboardTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $sub): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa ' . $sub, 'subdomain' => $sub,
            'plan' => 'enterprise', 'is_active' => true,
        ]);
    }

    private function makeUser(int $tenantId, string $email, string $role = 'empleado'): User
    {
        return User::create([
            'tenant_id' => $tenantId, 'name' => 'U ' . $email, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role,
        ]);
    }

    private function makeEmployeeUser(int $tenantId, string $email): User
    {
        $u = $this->makeUser($tenantId, $email);
        Employee::create([
            'tenant_id' => $tenantId, 'user_id' => $u->id, 'name' => 'E ' . $email,
            'is_active_employee' => true,
        ]);
        return $u;
    }

    private function insertLateCheckIn(int $tenantId, int $userId, string $date): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $tenantId, 'user_id' => $userId, 'date' => $date,
            'type' => 'check_in', 'time' => '09:30:00', 'is_late' => true, 'late_minutes' => 30,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_stats_counts_todays_late_in_tenant_timezone(): void
    {
        // 02:00 UTC del 11-jul = 20:00 del 10-jul en México (UTC-6): el ponche se fechó el 10.
        Carbon::setTestNow(Carbon::parse('2026-07-11 02:00:00'));
        try {
            $tenant = $this->makeTenant('dash-a');
            $admin = $this->makeUser($tenant->id, 'admin@dasha.local', 'admin');
            $emp = $this->makeEmployeeUser($tenant->id, 'emp@dasha.local');
            $localDate = Carbon::now('America/Mexico_City')->toDateString(); // 2026-07-10
            $this->insertLateCheckIn($tenant->id, $emp->id, $localDate);

            $response = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard/stats');

            $response->assertStatus(200);
            $this->assertSame(1, $response->json('data.retardos_hoy'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_monitor_shows_active_employee_in_tenant_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-11 02:00:00'));
        try {
            $tenant = $this->makeTenant('dash-b');
            $admin = $this->makeUser($tenant->id, 'admin@dashb.local', 'admin');
            $emp = $this->makeEmployeeUser($tenant->id, 'emp@dashb.local');
            $localDate = Carbon::now('America/Mexico_City')->toDateString();
            $this->insertLateCheckIn($tenant->id, $emp->id, $localDate);

            $response = $this->actingAs($admin)->getJson('/api/v1/admin/dashboard/monitor');

            $response->assertStatus(200);
            $ids = collect($response->json('data.users'))->pluck('id');
            $this->assertTrue($ids->contains($emp->id), 'el colaborador activo debe aparecer en el monitor');
        } finally {
            Carbon::setTestNow();
        }
    }
}
