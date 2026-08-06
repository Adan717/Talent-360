<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Auditoría N3 (2026-08-06) — la nómina se calculaba sobre la semana EN CURSO y contaba los
 * días futuros como faltas: un jueves cualquiera, 3 de 4 nóminas reales traían 6 faltas y
 * neto $0.00, y el colaborador podía FIRMAR ese cero a media semana (lo firmado es inmutable
 * para el batch, así que el cero quedaba congelado).
 *
 * Reglas de esta ronda:
 *  - Una falta sólo existe en un día que YA TERMINÓ (zona horaria del tenant); el día en
 *    curso y los futuros no son faltas.
 *  - `days_details[*].day_over` distingue "falta" de "día que aún no ocurre" (las pantallas
 *    pintaban "Falta Registrada" en días futuros).
 *  - La FIRMA del colaborador aplica siempre a la última semana CERRADA del tenant — el
 *    servidor decide el periodo, nunca el cliente.
 *  - `?period=closed` en /employee/payroll-weekly devuelve esa semana firmable.
 *  - Un expediente con salario_diario ya no deja `salary.base` indefinido (salía 0).
 */
class PayrollSemanaCerradaTest extends TestCase
{
    use RefreshDatabase;

    private int $tenantId = 2;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('tenants')->insertOrIgnore([
            'id' => $this->tenantId, 'name' => 'Empresa', 'subdomain' => 't2',
            'plan' => 'enterprise', 'max_users' => 20, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

    private function empleado(int $employeeId): \App\Models\Employee
    {
        return \App\Models\Employee::withoutGlobalScopes()->findOrFail($employeeId);
    }

    // ---------------- El guard de días futuros ----------------

    public function test_los_dias_futuros_no_son_faltas(): void
    {
        // Jueves 2026-08-06 (12:00 UTC = 06:00 en America/Mexico_City, mismo día).
        // Semana del tenant (default lunes): 3 al 9 de agosto. Sin un solo fichaje:
        // sólo lun/mar/mié (ya transcurridos) son faltas — no 6, como antes.
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00'));
        [, $employeeId] = $this->colaborador();

        $p = app(ClockService::class)->calculatePayrollForEmployee(
            $this->empleado($employeeId), '2026-08-03', '2026-08-09'
        );

        $this->assertSame(3, $p['incidents']['physical_absences'],
            'Sólo los días YA transcurridos (lun-mié) pueden ser falta; jue es hoy y vie-sáb futuros.');
        // daily 400: bruto 2800 − 3 faltas (1200) − medio séptimo día (200) = 1400, no $0.
        $this->assertSame(1400.0, (float) $p['salary']['net']);
    }

    public function test_day_over_distingue_falta_de_dia_no_ocurrido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00'));
        [, $employeeId] = $this->colaborador();

        $p = app(ClockService::class)->calculatePayrollForEmployee(
            $this->empleado($employeeId), '2026-08-03', '2026-08-09'
        );

        $flags = collect($p['days_details'])->pluck('day_over', 'date');
        $this->assertTrue($flags['2026-08-05'], 'el miércoles ya terminó');
        $this->assertFalse($flags['2026-08-06'], 'HOY aún no termina — no es falta');
        $this->assertFalse($flags['2026-08-08'], 'el sábado no ha ocurrido');
    }

    public function test_salario_diario_ya_no_deja_base_indefinida(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00'));
        [, $employeeId] = $this->colaborador();
        DB::table('employees')->where('id', $employeeId)
            ->update(['salario_diario' => 500, 'base_salary' => 3000]);

        $p = app(ClockService::class)->calculatePayrollForEmployee(
            $this->empleado($employeeId), '2026-08-03', '2026-08-09'
        );

        $this->assertSame(3000.0, (float) $p['salary']['base'],
            'Con salario_diario la base salía indefinida (0 en pantalla).');
        $this->assertSame(500.0, (float) $p['salary']['daily']);
    }

    // ---------------- La firma aplica a la semana CERRADA ----------------

    public function test_la_firma_cae_en_la_ultima_semana_cerrada_no_en_la_corriente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00')); // jueves
        [$user] = $this->colaborador();

        $this->actingAs($user)->postJson('/api/v1/employee/payroll-weekly/approve', [])
            ->assertStatus(200);

        $fila = DB::table('weekly_payrolls')->where('tenant_id', $this->tenantId)->first();
        $this->assertSame('2026-07-27', (string) $fila->start_date,
            'Se firma la semana CERRADA (27 jul – 2 ago), no la que va a la mitad.');
        $this->assertSame('2026-08-02', (string) $fila->end_date);
    }

    public function test_period_closed_devuelve_la_semana_firmable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00'));
        [$user] = $this->colaborador();

        $cerrada = $this->actingAs($user)->getJson('/api/v1/employee/payroll-weekly?period=closed');
        $cerrada->assertStatus(200);
        $this->assertSame('2026-07-27', $cerrada->json('data.period.start'));

        $corriente = $this->actingAs($user)->getJson('/api/v1/employee/payroll-weekly');
        $this->assertSame('2026-08-03', $corriente->json('data.period.start'),
            'Sin parámetro sigue siendo la vista en vivo de la semana en curso.');
    }

    public function test_admin_payroll_reporta_el_periodo_cerrado(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-06 12:00:00'));
        [$user] = $this->colaborador();
        DB::table('users')->where('id', $user->id)->update(['role' => 'admin']);

        $res = $this->actingAs($user->fresh())->getJson('/api/v1/admin/payroll');

        $res->assertStatus(200);
        $this->assertSame('2026-07-27', $res->json('period.start_date'));
        $this->assertIsArray($res->json('employees'));
    }
}
