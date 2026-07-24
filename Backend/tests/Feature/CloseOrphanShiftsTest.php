<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Comando nocturno shifts:close-orphans — cierra automáticamente las jornadas que
 * quedaron "Activas" (check_in sin check_out) tras la hora de cierre de la sucursal,
 * y registra una alerta en audit_logs para auditar posible fraude de nómina.
 */
class CloseOrphanShiftsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $closeTime = '18:00'): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Empresa Huérfanos',
            'subdomain' => 'orphan-test',
            'plan' => 'enterprise',
            'is_active' => true,
        ]);
        // system_settings.key es única POR TENANT: se matchea por (key, tenant_id).
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'storeSchedule', 'tenant_id' => $tenant->id],
            [
                'value' => json_encode(['openTime' => '08:00', 'closeTime' => $closeTime]),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        // tz UTC para el tenant: así la hora simulada (Carbon::setTestNow, tz app = UTC)
        // es la hora LOCAL del tenant y los asserts son deterministas.
        DB::table('system_settings')->updateOrInsert(
            ['key' => 'timezone', 'tenant_id' => $tenant->id],
            [
                'value' => json_encode('UTC'),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        return $tenant;
    }

    private function makeUser(int $tenantId): User
    {
        return User::create([
            'tenant_id' => $tenantId,
            'name' => 'Colaborador',
            'email' => 'emp' . uniqid() . '@t.local',
            'password' => bcrypt('password'),
            'role' => 'empleado',
        ]);
    }

    private function punch(int $tenantId, int $userId, string $type, string $time, string $date): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'date' => $date,
            'type' => $type,
            'time' => $time,
            'is_late' => false,
            'late_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_closes_orphaned_active_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:59:00'));
        try {
            $tenant = $this->makeTenant('18:00');
            $user = $this->makeUser($tenant->id);
            $today = now()->format('Y-m-d');
            $this->punch($tenant->id, $user->id, 'check_in', '09:00:00', $today);

            $this->artisan('shifts:close-orphans')->assertExitCode(0);

            // Check_out automático a la hora de cierre.
            $this->assertDatabaseHas('time_entries', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'type' => 'check_out',
                'time' => '18:00:00',
            ]);
            // Alerta de auditoría.
            $this->assertDatabaseHas('audit_logs', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'type' => 'orphan_shift',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_closes_reopened_orphaned_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:59:00'));
        try {
            $tenant = $this->makeTenant('18:00');
            $user = $this->makeUser($tenant->id);
            $today = now()->format('Y-m-d');
            // Turno 1 completo; luego re-entra (turno 2) y olvida checar salida.
            $this->punch($tenant->id, $user->id, 'check_in', '09:00:00', $today);
            $this->punch($tenant->id, $user->id, 'check_out', '13:00:00', $today);
            $this->punch($tenant->id, $user->id, 'check_in', '14:00:00', $today);

            $this->artisan('shifts:close-orphans')->assertExitCode(0);

            // El 2do turno se cierra: ahora hay 2 check_out (el manual 13:00 y el auto 18:00).
            $this->assertEquals(2, TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->count());
            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id,
                'type' => 'check_out',
                'time' => '18:00:00',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_does_not_close_completed_shift(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:59:00'));
        try {
            $tenant = $this->makeTenant('18:00');
            $user = $this->makeUser($tenant->id);
            $today = now()->format('Y-m-d');
            $this->punch($tenant->id, $user->id, 'check_in', '09:00:00', $today);
            $this->punch($tenant->id, $user->id, 'check_out', '17:30:00', $today);

            $this->artisan('shifts:close-orphans')->assertExitCode(0);

            // Solo el check_out manual (no se agrega un auto).
            $this->assertEquals(1, TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->count());
            $this->assertDatabaseMissing('audit_logs', ['user_id' => $user->id, 'type' => 'orphan_shift']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_does_not_close_before_store_closes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00'));
        try {
            $tenant = $this->makeTenant('18:00');
            $user = $this->makeUser($tenant->id);
            $today = now()->format('Y-m-d');
            $this->punch($tenant->id, $user->id, 'check_in', '09:00:00', $today);

            $this->artisan('shifts:close-orphans')->assertExitCode(0);

            // Aún no cierra la tienda (12:00 < 18:00): no se fuerza cierre.
            $this->assertDatabaseMissing('time_entries', ['user_id' => $user->id, 'type' => 'check_out']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_is_idempotent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:59:00'));
        try {
            $tenant = $this->makeTenant('18:00');
            $user = $this->makeUser($tenant->id);
            $today = now()->format('Y-m-d');
            $this->punch($tenant->id, $user->id, 'check_in', '09:00:00', $today);

            $this->artisan('shifts:close-orphans')->assertExitCode(0);
            $this->artisan('shifts:close-orphans')->assertExitCode(0);

            // El segundo run no encuentra huérfanos (ya tienen check_out).
            $this->assertEquals(1, TimeEntry::where('user_id', $user->id)->where('type', 'check_out')->count());
        } finally {
            Carbon::setTestNow();
        }
    }
}
