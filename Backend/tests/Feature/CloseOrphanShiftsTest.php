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

    private function makeUser(int $tenantId, string $shiftEnd = '18:00'): User
    {
        $user = User::create([
            'tenant_id' => $tenantId,
            'name' => 'Colaborador',
            'email' => 'emp' . uniqid() . '@t.local',
            'password' => bcrypt('password'),
            'role' => 'empleado',
        ]);
        // Con expediente: el barrido cierra al fin de turno de LA PERSONA, así que sin employee
        // la prueba no representaría a nadie real (todo colaborador tiene ficha).
        DB::table('employees')->insert([
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => $shiftEnd,
            'salary' => 3000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
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

    /** El comando tiene que estar AGENDADO: sin eso, todo lo de arriba es código muerto. */
    public function test_el_barrido_esta_agendado(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('shifts:close-orphans')
            ->assertExitCode(0);
    }

    /**
     * Si el horario programado NO explica la jornada, el sistema NO inventa la hora de salida.
     *
     * (2026-08-22) Con la regla anterior —`max(cierre, último ponche)`— una entrada a las 20:33
     * con turno que termina 07:45 producía una salida en el MISMO SEGUNDO: una jornada de CERO
     * minutos escrita sobre asistencia real. Ahora sólo queda la alerta para revisión humana.
     */
    public function test_no_inventa_la_salida_cuando_el_horario_no_explica_la_jornada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:59:00'));
        try {
            $tenant = $this->makeTenant('18:00');
            $user = $this->makeUser($tenant->id);
            $today = now()->format('Y-m-d');
            // Entra a las 19:00, mucho después del fin de su turno (18:00 de la tienda / 18:00 suyo).
            $this->punch($tenant->id, $user->id, 'check_in', '19:00:00', $today);

            $this->artisan('shifts:close-orphans')->assertExitCode(0);

            $this->assertDatabaseMissing('time_entries', [
                'user_id' => $user->id, 'type' => 'check_out',
            ]);
            $alerta = DB::table('audit_logs')->where('user_id', $user->id)->where('type', 'orphan_shift')->first();
            $this->assertNotNull($alerta, 'tiene que quedar la alerta para que un humano lo resuelva');
            $this->assertStringContainsString('no explica la jornada', (string) $alerta->reason);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** La salida se estampa al fin de turno de la PERSONA, no al cierre de la tienda. */
    public function test_usa_el_fin_de_turno_del_colaborador(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:59:00'));
        try {
            $tenant = $this->makeTenant('22:00');
            $user = $this->makeUser($tenant->id, '15:00');
            $today = now()->format('Y-m-d');
            $this->punch($tenant->id, $user->id, 'check_in', '09:00:00', $today);

            $this->artisan('shifts:close-orphans')->assertExitCode(0);

            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id, 'type' => 'check_out', 'time' => '15:00:00',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Se fue a comer y no volvió: se cierra la comida, marcada y SIN inventarle exceso. */
    public function test_cierra_la_comida_abierta_sin_inventar_exceso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:59:00'));
        try {
            $tenant = $this->makeTenant('18:00');
            $user = $this->makeUser($tenant->id, '18:00');
            $today = now()->format('Y-m-d');
            $this->punch($tenant->id, $user->id, 'check_in', '09:00:00', $today);
            $this->punch($tenant->id, $user->id, 'meal_start', '14:00:00', $today);

            $this->artisan('shifts:close-orphans')->assertExitCode(0);

            $comida = DB::table('time_entries')
                ->where('user_id', $user->id)->where('type', 'meal_end')->first();
            $this->assertNotNull($comida, 'la comida abierta tiene que cerrarse');
            $this->assertStringContainsString('meal_sin_regreso', (string) $comida->details);
            $this->assertDatabaseHas('time_entries', [
                'user_id' => $user->id, 'type' => 'check_out', 'time' => '18:00:00',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Los ponches del Simulador Matrix no son asistencia real y no se cierran. */
    public function test_ignora_los_fichajes_del_simulador(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:59:00'));
        try {
            $tenant = $this->makeTenant('18:00');
            $user = $this->makeUser($tenant->id);
            $today = now()->format('Y-m-d');
            $sesion = DB::table('simulator_sessions')->insertGetId([
                'tenant_id' => $tenant->id, 'simulated_date' => $today, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->punch($tenant->id, $user->id, 'check_in', '09:00:00', $today);
            DB::table('time_entries')->where('user_id', $user->id)->update(['simulation_session_id' => $sesion]);

            $this->artisan('shifts:close-orphans')->assertExitCode(0);

            $this->assertDatabaseMissing('time_entries', [
                'user_id' => $user->id, 'type' => 'check_out',
            ]);
            $this->assertDatabaseMissing('audit_logs', [
                'user_id' => $user->id, 'type' => 'orphan_shift',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** El huérfano de una jornada anterior (turno nocturno) también se cierra. */
    public function test_cierra_el_huerfano_de_la_jornada_de_ayer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 23:59:00'));
        try {
            $tenant = $this->makeTenant('18:00');
            $user = $this->makeUser($tenant->id);
            $ayer = now()->subDay()->format('Y-m-d');
            $this->punch($tenant->id, $user->id, 'check_in', '09:00:00', $ayer);

            $this->artisan('shifts:close-orphans')->assertExitCode(0);

            $this->assertDatabaseHas('time_entries', [
                'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'type' => 'check_out', 'date' => $ayer,
            ]);
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
