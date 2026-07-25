<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Remote Kill-Switch (spec:67 "Reacción Gerencial"): un admin/supervisor puede cerrar a
 * la fuerza la jornada activa de un infractor. Reutiliza el patrón de cierre forzado de
 * CloseOrphanShifts (insert directo de check_out + audit_logs + MonitorUpdated), con
 * scope de tenant y rol. v1 = force-close (pausa y lockout de re-ponche son follow-up).
 */
class KillSwitchTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $sub): Tenant
    {
        return Tenant::create([
            'name' => 'Empresa ' . $sub,
            'subdomain' => $sub,
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
    }

    private function makeUser(int $tenantId, string $email, string $role = 'empleado'): User
    {
        return User::create([
            'tenant_id' => $tenantId,
            'name' => 'U ' . $email,
            'email' => $email,
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
    }

    private function insertCheckIn(int $tenantId, int $userId, string $date, string $time = '09:00:00'): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'date' => $date,
            'type' => 'check_in',
            'time' => $time,
            'is_late' => false,
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_supervisor_force_closes_active_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            $tenant = $this->makeTenant('ks-a');
            $supervisor = $this->makeUser($tenant->id, 'sup@ks.local', 'supervisor');
            $target = $this->makeUser($tenant->id, 'target@ks.local');
            $today = now()->toDateString();
            $this->insertCheckIn($tenant->id, $target->id, $today);

            $response = $this->actingAs($supervisor)->postJson('/api/v1/admin/dashboard/force-close-shift', [
                'user_id' => $target->id,
            ]);

            $response->assertStatus(200);
            $this->assertDatabaseHas('time_entries', [
                'tenant_id' => $tenant->id, 'user_id' => $target->id, 'type' => 'check_out',
            ]);
            // Exactamente un check_out (no duplicados).
            $this->assertSame(1, DB::table('time_entries')
                ->where('user_id', $target->id)->where('type', 'check_out')->count());
            $this->assertDatabaseHas('audit_logs', [
                'tenant_id' => $tenant->id, 'user_id' => $target->id, 'type' => 'kill_switch',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_finds_active_shift_using_tenant_timezone_near_utc_midnight(): void
    {
        // 02:00 UTC del 11-jul = 20:00 del 10-jul en México (UTC-6). El check_in se fechó en
        // la fecha del tenant (10-jul); con fecha UTC (11-jul) el cierre no lo encontraría.
        Carbon::setTestNow(Carbon::parse('2026-07-11 02:00:00'));
        try {
            $tenant = $this->makeTenant('ks-tz');
            $supervisor = $this->makeUser($tenant->id, 'sup@kstz.local', 'supervisor');
            $target = $this->makeUser($tenant->id, 'target@kstz.local');
            $localDate = Carbon::now('America/Mexico_City')->toDateString(); // 2026-07-10
            $this->insertCheckIn($tenant->id, $target->id, $localDate);

            $response = $this->actingAs($supervisor)->postJson('/api/v1/admin/dashboard/force-close-shift', [
                'user_id' => $target->id,
            ]);

            $response->assertStatus(200);
            $this->assertDatabaseHas('time_entries', [
                'user_id' => $target->id, 'type' => 'check_out', 'date' => $localDate,
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_force_close_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            $tenant = $this->makeTenant('ks-idem');
            $supervisor = $this->makeUser($tenant->id, 'sup@ksi.local', 'supervisor');
            $target = $this->makeUser($tenant->id, 'target@ksi.local');
            $this->insertCheckIn($tenant->id, $target->id, now()->toDateString());

            $r1 = $this->actingAs($supervisor)->postJson('/api/v1/admin/dashboard/force-close-shift', ['user_id' => $target->id]);
            $r2 = $this->actingAs($supervisor)->postJson('/api/v1/admin/dashboard/force-close-shift', ['user_id' => $target->id]);

            $r1->assertStatus(200);
            $r2->assertStatus(409); // ya cerrado
            $this->assertSame(1, DB::table('time_entries')
                ->where('user_id', $target->id)->where('type', 'check_out')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_force_close_is_noop_when_not_active(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            $tenant = $this->makeTenant('ks-b');
            $supervisor = $this->makeUser($tenant->id, 'sup@ksb.local', 'admin');
            $target = $this->makeUser($tenant->id, 'target@ksb.local');
            // Sin check_in hoy → no hay turno activo.

            $response = $this->actingAs($supervisor)->postJson('/api/v1/admin/dashboard/force-close-shift', [
                'user_id' => $target->id,
            ]);

            $response->assertStatus(409);
            $this->assertDatabaseMissing('time_entries', [
                'user_id' => $target->id, 'type' => 'check_out',
            ]);
            $this->assertDatabaseMissing('audit_logs', [
                'user_id' => $target->id, 'type' => 'kill_switch',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_supervisor_cannot_force_close(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            $tenant = $this->makeTenant('ks-c');
            $employee = $this->makeUser($tenant->id, 'emp@ksc.local', 'empleado');
            $target = $this->makeUser($tenant->id, 'target@ksc.local');
            $this->insertCheckIn($tenant->id, $target->id, now()->toDateString());

            $response = $this->actingAs($employee)->postJson('/api/v1/admin/dashboard/force-close-shift', [
                'user_id' => $target->id,
            ]);

            $response->assertStatus(403); // RoleMiddleware
            $this->assertDatabaseMissing('time_entries', [
                'user_id' => $target->id, 'type' => 'check_out',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_cross_tenant_force_close_rejected(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        try {
            $tenantA = $this->makeTenant('ks-d1');
            $supervisor = $this->makeUser($tenantA->id, 'sup@ksd.local', 'supervisor');
            $tenantB = $this->makeTenant('ks-d2');
            $foreign = $this->makeUser($tenantB->id, 'foreign@ksd.local');
            $this->insertCheckIn($tenantB->id, $foreign->id, now()->toDateString());

            $response = $this->actingAs($supervisor)->postJson('/api/v1/admin/dashboard/force-close-shift', [
                'user_id' => $foreign->id,
            ]);

            $response->assertStatus(403);
            $this->assertDatabaseMissing('time_entries', [
                'user_id' => $foreign->id, 'type' => 'check_out',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}
