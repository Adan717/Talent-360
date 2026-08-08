<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\WeeklyPayroll;
use App\Services\ClockService;
use App\Services\PayrollWeekService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * #17 — Ciclo quincenal/mensual (decisión del jefe 2026-08-07, aprobado "arranca"):
 *
 *  - Periodos: semanal → semana configurable; quincenal → quincenas NATURALES (1-15 y
 *    16-fin de mes, 13 a 16 días según el mes); mensual → mes calendario.
 *  - Bruto por días REALES del periodo (quincena de febrero = 13 días; año = diario×365).
 *  - Séptimo día: proporcional POR SEMANA NATURAL del tenant dentro del periodo — la misma
 *    fórmula semanal (base 6), evaluada semana por semana. La falta acumulada por retardos
 *    pertenece a la semana del retardo que completó el trío.
 *  - El batch calcula el último periodo CERRADO de cada tenant según su periodicidad; un
 *    recibo firmado de OTRA periodicidad que traslape bloquea la generación (cambio de
 *    periodicidad sólo hacia adelante, sin doble pago).
 *  - La firma del colaborador y el default admin apuntan al último periodo cerrado.
 *  - CFDI: código del SAT por periodicidad (04 quincenal) y días del periodo REALES.
 */
class CicloQuincenalTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 2;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Empresa Q', 'subdomain' => 'q2',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function periodicidad(string $valor, ?int $tenantId = null): void
    {
        DB::table('system_settings')->updateOrInsert(
            ['tenant_id' => $tenantId ?? $this->tenantId, 'key' => 'payroll_periodicity'],
            ['value' => json_encode($valor), 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function colaborador(): array
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => $this->tenantId]);
        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'name' => $user->name,
            'email' => $user->email, 'base_salary' => 2400, 'restDay' => 'Domingo',
            'is_active_employee' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$user->fresh(), $employeeId];
    }

    private function checkIn(int $userId, string $date, bool $late = false, int $mins = 0): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $this->tenantId, 'user_id' => $userId, 'date' => $date,
            'type' => 'check_in', 'time' => $late ? '09:30:00' : '08:55:00',
            'is_late' => $late, 'late_minutes' => $mins,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---------------- El servicio de periodos ----------------

    public function test_quincenas_naturales_y_mes(): void
    {
        $svc = app(PayrollWeekService::class);
        $this->periodicidad('quincenal');

        [$s, $e] = $svc->periodRangeFor($this->tenantId, Carbon::parse('2026-08-07'));
        $this->assertSame(['2026-08-01', '2026-08-15'], [$s->toDateString(), $e->toDateString()]);

        [$s, $e] = $svc->periodRangeFor($this->tenantId, Carbon::parse('2026-08-20'));
        $this->assertSame(['2026-08-16', '2026-08-31'], [$s->toDateString(), $e->toDateString()]);

        // Febrero 2026 (no bisiesto): la segunda quincena tiene 13 días.
        [$s, $e] = $svc->periodRangeFor($this->tenantId, Carbon::parse('2026-02-20'));
        $this->assertSame(['2026-02-16', '2026-02-28'], [$s->toDateString(), $e->toDateString()]);

        $this->periodicidad('mensual');
        [$s, $e] = $svc->periodRangeFor($this->tenantId, Carbon::parse('2026-08-07'));
        $this->assertSame(['2026-08-01', '2026-08-31'], [$s->toDateString(), $e->toDateString()]);
    }

    public function test_ultimo_periodo_cerrado(): void
    {
        $svc = app(PayrollWeekService::class);
        $this->periodicidad('quincenal');

        [$s, $e] = $svc->lastClosedPeriodFor($this->tenantId, Carbon::parse('2026-08-07'));
        $this->assertSame(['2026-07-16', '2026-07-31'], [$s->toDateString(), $e->toDateString()]);

        [$s, $e] = $svc->lastClosedPeriodFor($this->tenantId, Carbon::parse('2026-08-20'));
        $this->assertSame(['2026-08-01', '2026-08-15'], [$s->toDateString(), $e->toDateString()]);

        $this->periodicidad('mensual');
        [$s, $e] = $svc->lastClosedPeriodFor($this->tenantId, Carbon::parse('2026-08-07'));
        $this->assertSame(['2026-07-01', '2026-07-31'], [$s->toDateString(), $e->toDateString()]);
    }

    // ---------------- El cálculo quincenal ----------------

    public function test_quincena_bruto_por_dias_reales_y_septimos_por_semana(): void
    {
        // Quincena Jul 1-15 2026 (15 días). Semana del tenant: lunes (default). Descanso:
        // domingo → 2 séptimos dentro del periodo (Jul 5 y Jul 12). Falta el MIÉRCOLES 8
        // (semana Jul 6-12): el séptimo del 12 baja a 5/6; el del 5 se paga completo.
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));
        $this->periodicidad('quincenal');
        [$user, $employeeId] = $this->colaborador();

        $laborales = ['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04',
            '2026-07-06', '2026-07-07', '2026-07-09', '2026-07-10', '2026-07-11',
            '2026-07-13', '2026-07-14', '2026-07-15']; // todos menos el 8 y los domingos
        foreach ($laborales as $d) {
            $this->checkIn($user->id, $d);
        }

        $p = app(ClockService::class)->calculatePayrollForEmployee(
            Employee::withoutGlobalScopes()->findOrFail($employeeId), '2026-07-01', '2026-07-15'
        );

        // daily 400 (2400/6). Bruto: 400 × 15 días = 6000 (no ×7).
        $this->assertSame(6000.0, (float) $p['salary']['gross']);
        $this->assertSame(1, $p['incidents']['physical_absences']);
        $this->assertSame(2, $p['incidents']['rest_days_in_period'], 'Jul 5 y Jul 12.');
        // Séptimo de la semana CON falta: (1 − 5/6) × 400 = 66.67. El de la semana limpia, íntegro.
        $this->assertEquals(66.67, round($p['deductions_breakdown']['rest_day'], 2));
        // Neto: 6000 − falta (400) − séptimo proporcional (66.67) = 5533.33.
        $this->assertEquals(5533.33, round($p['salary']['net'], 2));
    }

    public function test_falta_acumulada_baja_el_septimo_de_la_semana_del_tercer_retardo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));
        $this->periodicidad('quincenal');
        [$user, $employeeId] = $this->colaborador();

        // Asiste TODO el periodo, pero con 3 retardos en la semana Jul 6-12 (días 6, 7 y 9).
        $dias = ['2026-07-01', '2026-07-02', '2026-07-03', '2026-07-04',
            '2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10', '2026-07-11',
            '2026-07-13', '2026-07-14', '2026-07-15'];
        foreach ($dias as $d) {
            $late = in_array($d, ['2026-07-06', '2026-07-07', '2026-07-09'], true);
            $this->checkIn($user->id, $d, $late, $late ? 12 : 0);
        }

        $p = app(ClockService::class)->calculatePayrollForEmployee(
            Employee::withoutGlobalScopes()->findOrFail($employeeId), '2026-07-01', '2026-07-15'
        );

        $this->assertSame(0, $p['incidents']['physical_absences']);
        $this->assertSame(1, $p['incidents']['absences_from_lates'], '3 retardos = 1 falta.');
        // La falta acumulada pertenece a la semana Jul 6-12 (el 3er retardo fue el 9):
        // séptimo del 12 → 5/6; el del 5 completo → deducción 66.67, no 133.33.
        $this->assertEquals(66.67, round($p['deductions_breakdown']['rest_day'], 2));
        // Cobro único (N4): $0 por minuto con el default del art. 107.
        $this->assertSame(0.0, (float) $p['deductions_breakdown']['lates']);
        // Neto: 6000 − día de la falta acumulada (400) − 66.67 = 5533.33.
        $this->assertEquals(5533.33, round($p['salary']['net'], 2));
    }

    // ---------------- Batch, firma y candado ----------------

    public function test_el_batch_genera_la_quincena_cerrada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));
        $this->periodicidad('quincenal');
        [, $employeeId] = $this->colaborador();

        $this->artisan('payroll:calculate-weekly', ['--tenant_id' => $this->tenantId])
            ->assertExitCode(0);

        $this->assertDatabaseHas('weekly_payrolls', [
            'tenant_id' => $this->tenantId, 'employee_id' => $employeeId,
            'start_date' => '2026-07-16', 'end_date' => '2026-07-31', 'status' => 'draft',
        ]);
        $this->assertSame(1, WeeklyPayroll::where('employee_id', $employeeId)->count());
    }

    public function test_la_firma_cae_en_la_quincena_cerrada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));
        $this->periodicidad('quincenal');
        [$user] = $this->colaborador();

        $this->actingAs($user)->postJson('/api/v1/employee/payroll-weekly/approve', [])
            ->assertStatus(200);

        $fila = DB::table('weekly_payrolls')->where('tenant_id', $this->tenantId)->first();
        $this->assertSame('2026-07-16', (string) $fila->start_date);
        $this->assertSame('2026-07-31', (string) $fila->end_date);
        $this->assertSame('approved_by_employee', $fila->status);
    }

    public function test_candado_por_periodicidad_del_tenant(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));
        $this->periodicidad('quincenal');
        [$user] = $this->colaborador();
        DB::table('users')->where('id', $user->id)->update(['role' => 'admin']);
        $admin = $user->fresh();

        // La quincena real del tenant: pasa.
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/payroll?start_date=2026-07-16&end_date=2026-07-31')
            ->assertStatus(200);

        // Una semana suelta en un tenant quincenal: no es un periodo suyo → 422.
        $this->actingAs($admin)
            ->getJson('/api/v1/admin/payroll?start_date=2026-07-14&end_date=2026-07-20')
            ->assertStatus(422);
    }

    public function test_un_recibo_firmado_de_otra_periodicidad_bloquea_la_quincena_que_traslapa(): void
    {
        // La empresa era SEMANAL y el trabajador firmó la semana Jul 20-26. Luego cambian a
        // quincenal: la quincena cerrada Jul 16-31 TRASLAPA esa semana firmada — generarla
        // pagaría esos días dos veces. El batch la omite (cambio sólo hacia adelante).
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));
        $this->periodicidad('quincenal');
        [, $employeeId] = $this->colaborador();

        WeeklyPayroll::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employeeId,
            'start_date' => '2026-07-20', 'end_date' => '2026-07-26',
            'base_salary_paid' => 2400, 'net_pay' => 2800, 'status' => 'approved_by_employee',
            'employee_approved_at' => now(),
        ]);

        $this->artisan('payroll:calculate-weekly', ['--tenant_id' => $this->tenantId])
            ->assertExitCode(0);

        $this->assertSame(1, WeeklyPayroll::where('employee_id', $employeeId)->count(),
            'Sólo la semana firmada: la quincena traslapada NO se genera.');
        $this->assertDatabaseMissing('weekly_payrolls', [
            'employee_id' => $employeeId, 'start_date' => '2026-07-16',
        ]);
    }

    // ---------------- CFDI ----------------

    public function test_cfdi_quincenal_manda_04_y_los_dias_reales(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-07 12:00:00'));
        $this->periodicidad('quincenal');
        Http::fake(['api.facturapi.com/*' => Http::response(['id' => 'rec_q1', 'uuid' => 'QQQ-UUID-04'], 200)]);

        [$user, $employeeId] = $this->colaborador();
        DB::table('users')->where('id', $user->id)->update(['role' => 'admin']);
        WeeklyPayroll::create([
            'tenant_id' => $this->tenantId, 'employee_id' => $employeeId,
            'start_date' => '2026-07-16', 'end_date' => '2026-07-31',
            'base_salary_paid' => 2400, 'net_pay' => 3100, 'status' => 'approved_by_admin',
            'employee_approved_at' => now(),
        ]);

        $res = $this->actingAs($user->fresh())->postJson('/api/v1/billing/payroll/timbrar', [
            'employee_id' => $employeeId,
            'period_start' => '2026-07-16',
            'period_end' => '2026-07-31',
        ]);

        $res->assertStatus(200);
        $this->assertSame('QQQ-UUID-04', $res->json('receipt.uuid'));

        Http::assertSent(function ($request) {
            $payroll = $request['payroll'] ?? [];
            return ($payroll['employee']['periodicity'] ?? null) === '04'   // quincenal ante el SAT
                && ($payroll['working_days'] ?? null) === 16                // Jul 16-31: 16 días REALES
                && ($payroll['employee']['salary_rate'] ?? null) === round(3100 / 16, 2);
        });
    }
}
