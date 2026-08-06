<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WeeklyPayroll;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * A4 (auditoría 2026-07-27) — el comando payroll:calculate-weekly estaba TRIPLEMENTE roto:
 *
 *  1. Escrito contra un esquema imaginario: weekly_payrolls NO tiene week_start/week_end/
 *     gross_salary/net_salary/metrics/…, y calculatePayrollForEmployee no devuelve
 *     gross_total/net_total/… (devuelve salary.base, salary.net, incidents.x, performance.x —
 *     el contrato que SÍ usa EmployeePayrollController). Resultado: cada corrida nocturna
 *     tronaba por-empleado (columna inexistente), regresaba FAILURE y JAMÁS persistió una
 *     pre-nómina.
 *  2. Agendado DOS veces (bootstrap dailyAt 23:00 + console.php weeklyOn sábado 23:59).
 *  3. Lookup sólo por status='draft': con una fila ya firmada de la misma semana, creaba
 *     una fila DUPLICADA de nómina (o reventaba contra el unique).
 *
 * Reglas de esta ronda:
 *  - El comando persiste con el esquema REAL (mismas columnas/claves que el flujo de firma).
 *  - Una fila no-draft (firmada) de la misma semana NO se toca ni se duplica.
 *  - Un draft existente se recalcula en sitio (una sola fila por empleado+semana).
 *  - El schedule queda registrado UNA sola vez (la variante diaria fiscal-week-aware).
 */
class WeeklyPayrollCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => 1, 'name' => 'DecorArte', 'subdomain' => 't1', 'plan' => 'enterprise',
            'max_users' => 50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEmployee(): array
    {
        $user = User::factory()->create(['role' => 'empleado']);
        DB::table('users')->where('id', $user->id)->update(['tenant_id' => 1]);

        $employeeId = DB::table('employees')->insertGetId([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'base_salary' => 300.00,
            'is_active_employee' => true,
            'shiftStart' => '09:00:00',
            'shiftEnd' => '18:00:00',
            'restDay' => 'Domingo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$user->fresh(), $employeeId];
    }

    /**
     * Última semana CERRADA del tenant 1 (DecorArte: domingo→sábado). N3: el batch calcula
     * la semana que ya terminó, nunca la corriente (contaba días futuros como faltas).
     */
    private function closedWeekStart(): string
    {
        [$start] = app(\App\Services\PayrollWeekService::class)->lastClosedWeekFor(1, Carbon::now());
        return $start->toDateString();
    }

    public function test_comando_persiste_draft_con_el_esquema_real(): void
    {
        [, $employeeId] = $this->makeEmployee();

        $this->artisan('payroll:calculate-weekly', ['--tenant_id' => 1])
            ->assertExitCode(0);

        $this->assertDatabaseHas('weekly_payrolls', [
            'tenant_id' => 1,
            'employee_id' => $employeeId,
            'start_date' => $this->closedWeekStart(),
            'status' => 'draft',
        ]);
        $this->assertSame(1, WeeklyPayroll::where('employee_id', $employeeId)->count());
    }

    public function test_fila_firmada_no_se_toca_ni_se_duplica(): void
    {
        [, $employeeId] = $this->makeEmployee();
        $weekStart = $this->closedWeekStart();
        $weekEnd = Carbon::parse($weekStart)->addDays(6)->toDateString();

        WeeklyPayroll::create([
            'tenant_id' => 1,
            'employee_id' => $employeeId,
            'start_date' => $weekStart,
            'end_date' => $weekEnd,
            'base_salary_paid' => 999.99,
            'net_pay' => 999.99,
            'status' => 'approved_by_employee',
            'employee_approved_at' => now(),
        ]);

        $this->artisan('payroll:calculate-weekly', ['--tenant_id' => 1])
            ->assertExitCode(0);

        // Una sola fila, intacta: lo firmado por el empleado es inmutable para el batch.
        $this->assertSame(1, WeeklyPayroll::where('employee_id', $employeeId)->count());
        $this->assertDatabaseHas('weekly_payrolls', [
            'employee_id' => $employeeId,
            'start_date' => $weekStart,
            'net_pay' => 999.99,
            'status' => 'approved_by_employee',
        ]);
    }

    public function test_draft_existente_se_recalcula_sin_duplicar(): void
    {
        [, $employeeId] = $this->makeEmployee();
        $weekStart = $this->closedWeekStart();
        $weekEnd = Carbon::parse($weekStart)->addDays(6)->toDateString();

        WeeklyPayroll::create([
            'tenant_id' => 1,
            'employee_id' => $employeeId,
            'start_date' => $weekStart,
            'end_date' => $weekEnd,
            'base_salary_paid' => 123.45, // número stale que el recálculo debe pisar
            'net_pay' => 123.45,
            'status' => 'draft',
        ]);

        $this->artisan('payroll:calculate-weekly', ['--tenant_id' => 1])
            ->assertExitCode(0);

        $this->assertSame(1, WeeklyPayroll::where('employee_id', $employeeId)->count());
        $row = WeeklyPayroll::where('employee_id', $employeeId)->first();
        $this->assertSame('draft', $row->status);
        // Recalculado de verdad (sin fichajes la semana vale 0, no el stale 123.45).
        $this->assertNotEquals(123.45, (float) $row->net_pay);
    }

    public function test_el_schedule_registra_el_comando_una_sola_vez(): void
    {
        // Forzar la carga de routes/console.php (el kernel de consola es perezoso en
        // el contexto de un feature test — sin esto el conteo sale incompleto).
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->all();

        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) ($e->command ?? ''), 'payroll:calculate-weekly'));

        $this->assertSame(
            1,
            $events->count(),
            'payroll:calculate-weekly debe estar agendado exactamente una vez (estaba en bootstrap Y en console.php).'
        );
    }
}
