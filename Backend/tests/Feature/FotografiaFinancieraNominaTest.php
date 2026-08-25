<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WeeklyPayroll;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La fotografía financiera mira y no toca (Fase 0, 2026-08-24).
 *
 * Es el guardarraíl que el consejo echó en falta: correr el motor sobre lo ya guardado y sacar la
 * diferencia EN PESOS, para no tener que creerle a nadie que "el cambio no movió dinero". Un
 * informe que modificara lo que mide no serviría de nada, así que aquí se fija lo que importa:
 * detecta la diferencia cuando la hay, y no escribe absolutamente nada — ni siquiera la política
 * LFT que el motor de nómina crea por su cuenta cuando falta.
 */
class FotografiaFinancieraNominaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00'));
        $this->tenant = Tenant::create(['name' => 'Foto QA', 'subdomain' => 'fotoqa', 'plan' => 'enterprise', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function persona(string $nombre, array $extra = []): Employee
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '', $nombre)) . '@fotoqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'restDay' => 'Domingo', 'base_salary' => 2100, 'hire_date' => '2026-01-01',
        ], $extra));
    }

    private function nominaGuardada(Employee $emp, float $neto, int $faltas = 0, string $status = 'draft'): WeeklyPayroll
    {
        return WeeklyPayroll::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id,
            'start_date' => '2026-08-10', 'end_date' => '2026-08-16',
            'base_salary_paid' => 2100, 'lates_count' => 0, 'absences_count' => $faltas,
            'rest_day_proportion' => 1.00, 'deductions' => 0, 'net_pay' => $neto,
            'status' => $status,
        ]);
    }

    public function test_no_escribe_nada_ni_siquiera_la_politica_lft_que_el_motor_crea_solo(): void
    {
        $emp = $this->persona('Sin Politica');
        $this->nominaGuardada($emp, 2450);

        // A propósito SIN LftSetting: el motor la crea si no existe, y una fotografía no puede
        // cambiar la configuración de una empresa por el hecho de mirarla.
        $this->assertDatabaseCount('lft_settings', 0);

        $antes = $this->huellaDeLaBase();
        $this->artisan('nomina:fotografia')->assertExitCode(0);

        // (el 3er argumento de assertDatabaseCount es la conexión, no un mensaje)
        $this->assertDatabaseCount('lft_settings', 0);
        $this->assertSame($antes, $this->huellaDeLaBase(), 'el informe modificó la base de datos');
    }

    public function test_delata_la_nomina_firmada_que_hoy_pagaria_distinto(): void
    {
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);
        // Contratada a mitad del periodo: es el defecto que ya se corrigió (se le cobraban como
        // faltas los días anteriores a su alta). La nómina firmada quedó con esas faltas.
        $emp = $this->persona('Rosa Recien', ['hire_date' => '2026-08-14']);
        $this->nominaGuardada($emp, 100.00, 6, 'approved_by_admin');

        $salida = $this->artisan('nomina:fotografia')->assertExitCode(0);
        $salida->expectsOutputToContain('HAY NÓMINAS YA FIRMADAS CON DIFERENCIA');
        $salida->expectsOutputToContain('Rosa Recien');
        $salida->run();
    }

    public function test_una_nomina_que_cuadra_no_levanta_alarma(): void
    {
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);
        $emp = $this->persona('Cuadra Bien');

        // Se guarda exactamente lo que el motor calcula hoy para ese periodo.
        $hoy = app(\App\Services\ClockService::class)
            ->calculatePayrollForEmployee($emp, '2026-08-10', '2026-08-16');
        $this->nominaGuardada(
            $emp,
            round((float) $hoy['salary']['net'], 2),
            (int) $hoy['incidents']['total_absences'],
            'approved_by_admin'
        );

        $salida = $this->artisan('nomina:fotografia')->assertExitCode(0);
        $salida->expectsOutputToContain('Ninguna nómina guardada cambia con el motor de hoy');
        $salida->run();
    }

    public function test_mide_el_sobrepago_de_la_formula_legada_entre_seis(): void
    {
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);
        // $2,100 semanales: /6 da $350 diarios y $2,450 de bruto; /7 daría $300 y $2,100.
        // El sobrepago es exactamente el sueldo entre 6 = $350 por semana.
        $this->persona('Por La Legada', ['base_salary' => 2100]);

        $d = $this->informeJson()['divisor_seis'];

        $this->assertEquals(350.00, $d['diferencia_total_por_semana']);
        $this->assertEquals(18200.00, $d['diferencia_total_por_anio']);
        $this->assertCount(1, $d['por_la_formula_legada']);
        $this->assertEquals(350.00, $d['por_la_formula_legada'][0]['diario_hoy']);
        $this->assertEquals(300.00, $d['por_la_formula_legada'][0]['diario_si_fuera_semanal']);
        $this->assertEquals(2450.00, $d['por_la_formula_legada'][0]['bruto_semana_hoy']);
        $this->assertEquals(2100.00, $d['por_la_formula_legada'][0]['bruto_semana_si_fuera_semanal']);
    }

    public function test_separa_a_quien_declara_su_diario_y_a_quien_no_tiene_sueldo(): void
    {
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);
        $this->persona('Con Diario', ['salario_diario' => 400]);
        $this->persona('Por La Legada', ['base_salary' => 2100]);
        $this->persona('Sin Sueldo', ['base_salary' => null, 'salary' => null]);

        $d = $this->informeJson()['divisor_seis'];

        $this->assertSame(1, $d['con_diario_declarado'], 'a quien declara su diario no le aplica el /6');
        $this->assertSame(1, $d['sin_sueldo_capturado'], 'quien no tiene sueldo se cuenta aparte, no se le inventa uno');
        $this->assertCount(1, $d['por_la_formula_legada']);
    }

    /** Huella de las tablas que el informe podría llegar a tocar. */
    private function huellaDeLaBase(): string
    {
        $tablas = ['lft_settings', 'weekly_payrolls', 'employees', 'time_entries', 'audit_logs', 'system_settings'];
        $huella = [];

        foreach ($tablas as $t) {
            $huella[$t] = DB::table($t)->count();
        }

        return json_encode($huella);
    }

    /** Corre el informe en JSON y devuelve lo que imprimió, ya decodificado. */
    private function informeJson(): array
    {
        $this->assertSame(0, Artisan::call('nomina:fotografia', ['--json' => true]));

        return json_decode(Artisan::output(), true);
    }
}
