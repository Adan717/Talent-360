<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nadie falta antes de ser contratado (2026-08-22, fase 11 del guion).
 *
 * El conteo de faltas miraba festivos, día de descanso, contingencias y días ya transcurridos,
 * pero NUNCA la fecha de ingreso. A quien se contrata a mitad de periodo se le cobraban como
 * faltas los días anteriores a su alta, y como cada falta descuenta un día de salario, su primera
 * nómina salía en CERO. Visto en vivo: una candidata contratada hoy aparecía con 6 faltas en un
 * periodo que terminó una semana antes de que existiera su expediente.
 */
class NominaNoCobraFaltasAntesDelAltaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Nomina QA', 'subdomain' => 'nominaqa', 'plan' => 'enterprise', 'is_active' => true]);
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);
    }

    private function persona(string $nombre, ?string $alta, ?string $baja = null): Employee
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '', $nombre)) . '@nominaqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'salary' => 2100, 'restDay' => 'Domingo',
            'hire_date' => $alta, 'termination_date' => $baja,
        ]);
    }

    /** Semana cerrada del lunes 10 al domingo 16 de agosto de 2026, ya transcurrida. */
    private function nomina(Employee $emp): array
    {
        return app(ClockService::class)->calculatePayrollForEmployee($emp, '2026-08-10', '2026-08-16');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_contratado_despues_del_periodo_no_acumula_faltas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00'));
        $recien = $this->persona('Rosa Recien', '2026-08-23');

        $r = $this->nomina($recien);

        $this->assertSame(0, (int) $r['incidents']['physical_absences'], 'no puede faltar a días en los que no trabajaba aquí');
    }

    public function test_contratado_a_mitad_del_periodo_solo_falta_desde_su_alta(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00'));
        // Alta el jueves 13: quedan jueves, viernes y sábado (el domingo es descanso).
        $emp = $this->persona('Media Semana', '2026-08-13');

        $r = $this->nomina($emp);

        $this->assertSame(3, (int) $r['incidents']['physical_absences'], 'sólo los días laborables desde su alta');
    }

    public function test_quien_ya_estaba_contratado_falta_toda_la_semana(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00'));
        $viejo = $this->persona('Antiguo Empleado', '2026-01-01');

        $r = $this->nomina($viejo);

        $this->assertSame(6, (int) $r['incidents']['physical_absences'], 'lunes a sábado, sin el domingo de descanso');
    }

    public function test_despues_de_la_baja_tampoco_se_le_cobran_faltas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 12:00:00'));
        // Se fue el miércoles 12: cuentan lunes, martes y miércoles.
        $baja = $this->persona('Ya Se Fue', '2026-01-01', '2026-08-12');

        $r = $this->nomina($baja);

        $this->assertSame(3, (int) $r['incidents']['physical_absences'], 'después de la baja ya no es su falta');
    }
}
