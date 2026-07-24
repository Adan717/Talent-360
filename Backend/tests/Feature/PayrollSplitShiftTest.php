<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reloj R68 (bug de dinero cazado por auditoría adversarial de nómina): el retardo se calculaba para
 * CUALQUIER check_in contra el único `shiftStart` del empleado. La jornada partida
 * (check_in→check_out→check_in el mismo día) es un flujo LEGÍTIMO (R63 + CloseOrphanShifts lo
 * soportan), así que el 2º check_in (regreso, p.ej. 15:00) quedaba `is_late=true`,
 * `late_minutes = 15:00 − 09:00 = 360`. La nómina SUMA `late_minutes` por entrada
 * (calculatePayrollForEmployee) → sobre-deducción hasta $0 + faltas fantasma (floor(latesCount/3)).
 * Y con `max_late_block_minutes > 0`, ese 2º check_in superaba el umbral → BLOQUEABA el regreso.
 *
 * Fix: el retardo (y su bloqueo) sólo se evalúan para el PRIMER check_in del día. Un 2º check_in es
 * reanudación → puntual (no hay un 2º shiftStart contra el cual medir).
 */
class PayrollSplitShiftTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(int $maxLateBlock = 0): array
    {
        $tenant = Tenant::create(['name' => 'Empresa SS', 'subdomain' => 'ss' . uniqid(), 'plan' => 'enterprise', 'is_active' => true]);
        $user = User::create([
            'tenant_id' => $tenant->id, 'name' => 'Colab', 'email' => 'ss' . uniqid() . '@t.local',
            'password' => bcrypt('password'), 'role' => 'empleado',
        ]);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'Colab',
            'shiftStart' => '09:00:00', 'restDay' => 'Domingo', 'mealMinutes' => 60, 'is_active_employee' => true,
        ]);
        DB::table('system_settings')->insert([
            'tenant_id' => $tenant->id, 'key' => 'timezone', 'value' => json_encode('UTC'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        LftSetting::create([
            'tenant_id' => $tenant->id, 'late_tolerance_minutes' => 10,
            'max_late_block_minutes' => $maxLateBlock, 'late_penalty_per_minute' => 2,
        ]);
        return [$tenant, $user, $employee];
    }

    private function lastCheckIn(int $userId): object
    {
        return DB::table('time_entries')->where('user_id', $userId)->where('type', 'check_in')
            ->orderByDesc('id')->first();
    }

    /** El 2º check_in de una jornada partida NO se marca tarde contra el shiftStart de la mañana. */
    public function test_el_segundo_checkin_de_jornada_partida_no_es_tarde(): void
    {
        [, $user] = $this->makeUser();
        $svc = app(ClockService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        $svc->processPunch($user, 'check_in');   // primero, puntual
        Carbon::setTestNow(Carbon::parse('2026-07-10 13:00:00'));
        $svc->processPunch($user, 'check_out');
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        $svc->processPunch($user, 'check_in');   // regreso — NO debe ser tarde
        Carbon::setTestNow();

        $ultimo = $this->lastCheckIn($user->id);
        $this->assertFalse((bool) $ultimo->is_late, 'el regreso de jornada partida no es retardo');
        $this->assertSame(0, (int) $ultimo->late_minutes);
    }

    /** El PRIMER check_in tarde SÍ se marca (no se rompió la detección real de retardo). */
    public function test_el_primer_checkin_tarde_si_se_marca(): void
    {
        [, $user] = $this->makeUser();
        $svc = app(ClockService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00')); // 30 min tarde (tol 10)
        $svc->processPunch($user, 'check_in');
        Carbon::setTestNow();

        $ci = $this->lastCheckIn($user->id);
        $this->assertTrue((bool) $ci->is_late);
        $this->assertGreaterThanOrEqual(29, (int) $ci->late_minutes);
    }

    /** La nómina no se infla: una jornada partida puntual no genera retardo ni falta fantasma. */
    public function test_la_nomina_no_se_infla_por_jornada_partida(): void
    {
        [, $user, $employee] = $this->makeUser();
        $svc = app(ClockService::class);

        // Viernes 2026-07-10, jornada partida puntual.
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        $svc->processPunch($user, 'check_in');
        Carbon::setTestNow(Carbon::parse('2026-07-10 13:00:00'));
        $svc->processPunch($user, 'check_out');
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        $svc->processPunch($user, 'check_in');
        Carbon::setTestNow(Carbon::parse('2026-07-10 18:00:00'));
        $svc->processPunch($user, 'check_out');
        Carbon::setTestNow();

        $totalLate = (int) DB::table('time_entries')->where('user_id', $user->id)
            ->where('date', '2026-07-10')->sum('late_minutes');
        $this->assertSame(0, $totalLate, 'jornada partida puntual → 0 minutos de retardo');

        $payroll = $svc->calculatePayrollForEmployee($employee, '2026-07-10', '2026-07-10');
        $this->assertSame(0, (int) ($payroll['incidents']['lates'] ?? -1), 'sin retardos en la nómina');
    }

    /**
     * Primer check_in TARDE + reanudación puntual: el retardo del día es SÓLO el del primero; el 2º
     * check_in no suma ni resta. (Fija que la reanudación no toca el retardo ya cobrado.)
     */
    public function test_solo_el_primer_checkin_aporta_retardo(): void
    {
        [, $user] = $this->makeUser();
        $svc = app(ClockService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-10 09:30:00')); // 30 tarde
        $svc->processPunch($user, 'check_in');
        Carbon::setTestNow(Carbon::parse('2026-07-10 13:00:00'));
        $svc->processPunch($user, 'check_out');
        Carbon::setTestNow(Carbon::parse('2026-07-10 14:00:00'));
        $svc->processPunch($user, 'check_in'); // reanudación
        Carbon::setTestNow();

        $total = (int) DB::table('time_entries')->where('user_id', $user->id)
            ->where('date', '2026-07-10')->sum('late_minutes');
        $this->assertGreaterThanOrEqual(29, $total);
        $this->assertLessThan(60, $total, 'sólo el primer check_in aporta retardo, el regreso no suma');
    }

    /**
     * DECISIÓN CONOCIDA (riesgo aceptado, inherente al modelo de un solo shiftStart): si el PRIMER
     * check_in es puntual, un 2º check_in TARDÍO NO se penaliza (es reanudación de jornada partida).
     * Evadir un retardo real así exige un primer check_in falso-temprano, acotado por los controles
     * anti-fraude de ponche (geocerca/IP-lock/PIN de kiosko). Este test FIJA esa decisión.
     */
    public function test_reanudacion_tardia_tras_primer_checkin_puntual_no_se_penaliza(): void
    {
        [, $user] = $this->makeUser();
        $svc = app(ClockService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00')); // puntual
        $svc->processPunch($user, 'check_in');
        Carbon::setTestNow(Carbon::parse('2026-07-10 09:05:00'));
        $svc->processPunch($user, 'check_out');
        Carbon::setTestNow(Carbon::parse('2026-07-10 11:00:00')); // regreso "tarde", pero es reanudación
        $svc->processPunch($user, 'check_in');
        Carbon::setTestNow();

        $ultimo = $this->lastCheckIn($user->id);
        $this->assertFalse((bool) $ultimo->is_late, 'la reanudación no se penaliza (decisión conocida)');
        $this->assertSame(0, (int) $ultimo->late_minutes);
    }

    /** Con max_late_block > 0, el regreso de jornada partida NO se bloquea (no lanza excepción). */
    public function test_el_regreso_no_se_bloquea_con_retardo_extremo(): void
    {
        [, $user] = $this->makeUser(maxLateBlock: 60);
        $svc = app(ClockService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-10 09:00:00'));
        $svc->processPunch($user, 'check_in');
        Carbon::setTestNow(Carbon::parse('2026-07-10 13:00:00'));
        $svc->processPunch($user, 'check_out');
        Carbon::setTestNow(Carbon::parse('2026-07-10 15:00:00'));
        // Sin el fix: late_minutes=360 > 60 → lanzaría "Retardo Extremo" y bloquearía el regreso.
        $svc->processPunch($user, 'check_in');
        Carbon::setTestNow();

        $this->assertSame(2, DB::table('time_entries')->where('user_id', $user->id)
            ->where('type', 'check_in')->count(), 'el regreso se registró (no fue bloqueado)');
    }
}
