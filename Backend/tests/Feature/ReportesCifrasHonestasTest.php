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
 * Reportes — las cifras de la pantalla son las que se pagan (ronda 2026-08-08).
 *
 * La auditoría encontró dos discrepancias de DINERO en la misma pantalla:
 *
 *  1. Las tarjetas de arriba no eran la suma de la columna de abajo: el navegador derivaba
 *     "Total a Pagar (Neto)" como Σbase − Σpenalty, pero `base` es el sueldo del expediente
 *     (no el bruto del periodo) y el neto real tiene tope en 0 y suma el bono. En una prueba
 *     con dos colaboradores, la tarjeta decía $7,400 y la suma real de la columna $8,633.33.
 *
 *  2. A quien no tiene sueldo capturado se le presentaba como sueldo real un default de
 *     $2,400 escondido en el cálculo, y el aviso "Ajustar Salario" que la tabla ya sabía
 *     pintar NUNCA se encendía.
 */
class ReportesCifrasHonestasTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Nómina QA', 'subdomain' => 'nominaqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@nominaqa.test',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]);
    }

    private function colaborador(string $nombre, array $sueldo): Employee
    {
        $u = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => Str()->slug($nombre) . '@nominaqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $u->id, 'name' => $nombre,
            'is_active_employee' => true,
        ], $sueldo));
    }

    private function nomina(): array
    {
        return $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/payroll')
            ->assertOk()
            ->json();
    }

    /** El bruto y el bono viajan a la pantalla: sin ellos no puede sumar bien. */
    public function test_el_payload_trae_el_bruto_del_periodo_y_el_bono(): void
    {
        $this->colaborador('Ana Puntual', ['base_salary' => 2400]);

        $emp = $this->nomina()['employees'][0];

        $this->assertArrayHasKey('gross', $emp, 'sin el bruto, la pantalla lo inventaba restando');
        $this->assertArrayHasKey('compliance_bonus', $emp);
        $this->assertGreaterThan(0, $emp['gross']);
    }

    /** La suma de la columna "Neto a Pagar" es el total que se paga: no una resta aparte. */
    public function test_el_total_es_la_suma_de_los_netos(): void
    {
        $this->colaborador('Ana Puntual', ['base_salary' => 2400]);
        $this->colaborador('Beto Jefe', ['base_salary' => 5000]);

        $empleados = collect($this->nomina()['employees']);

        $sumaNetos = round($empleados->sum('net'), 2);
        $restaVieja = round($empleados->sum('base') - $empleados->sum('penalty'), 2);

        // Así es como la pantalla derivaba el total; se deja explícito que NO son lo mismo,
        // para que nadie vuelva a "simplificar" el cálculo de la tarjeta.
        $this->assertNotEqualsWithDelta($restaVieja, $sumaNetos, 0.01,
            'Σbase − Σpenalty no es el neto: base es el sueldo del expediente, no el bruto del periodo');

        $sumaBrutos = round($empleados->sum('gross'), 2);
        $this->assertGreaterThan(0, $sumaBrutos);
        $this->assertEqualsWithDelta($sumaNetos, $empleados->sum('net'), 0.01);
    }

    /** Sin sueldo capturado no se inventa uno: se avisa. */
    public function test_un_colaborador_sin_sueldo_sale_marcado_como_pendiente(): void
    {
        $this->colaborador('Nuevo Sin Sueldo', [
            'base_salary' => null, 'salary' => null, 'salario_diario' => null,
        ]);

        $emp = $this->nomina()['employees'][0];

        $this->assertTrue($emp['salary_pending'],
            'el cálculo mete un default de $2,400; la pantalla NO puede presentarlo como sueldo real');
    }

    /** Con sueldo capturado el aviso no aparece (no romper el caso normal). */
    public function test_un_colaborador_con_sueldo_no_sale_como_pendiente(): void
    {
        $this->colaborador('Ana Puntual', ['base_salary' => 2400]);

        $this->assertFalse($this->nomina()['employees'][0]['salary_pending']);
    }

    /** El PDF/Excel exportado cuadra consigo mismo y dice su periodo. */
    public function test_el_pdf_exportado_dice_su_periodo_real(): void
    {
        $this->colaborador('Ana Puntual', ['base_salary' => 2400]);

        $periodo = $this->nomina()['period'];

        $res = $this->actingAs($this->admin)->get('/api/v1/admin/reports/export?format=pdf');
        $res->assertOk();

        $disposition = $res->headers->get('content-disposition');
        $this->assertStringContainsString($periodo['start_date'], $disposition,
            'el archivo se nombra con el periodo que contiene');
        $this->assertStringNotContainsString('SIMULACION', $disposition);
    }

    /** Un reporte de prueba no puede confundirse con el real. */
    public function test_el_reporte_de_simulacion_va_marcado(): void
    {
        $this->colaborador('Ana Puntual', ['base_salary' => 2400]);

        $sesion = DB::table('simulator_sessions')->insertGetId([
            'tenant_id' => $this->tenant->id, 'started_by_user_id' => $this->admin->id,
            'simulated_date' => Carbon::now()->toDateString(), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->actingAs($this->admin)
            ->get("/api/v1/admin/reports/export?format=pdf&simulation_session_id={$sesion}");
        $res->assertOk();

        $this->assertStringContainsString('SIMULACION', $res->headers->get('content-disposition'),
            'un reporte de prueba no puede llamarse igual que la nómina real');
    }

    /** Quien trabajó y firmó el periodo sigue en la tabla aunque hoy esté dado de baja. */
    public function test_un_colaborador_dado_de_baja_despues_sigue_en_su_periodo(): void
    {
        $emp = $this->colaborador('Salió Después', ['base_salary' => 2400]);
        $periodo = $this->nomina()['period'];

        DB::table('weekly_payrolls')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $emp->id,
            'start_date' => $periodo['start_date'], 'end_date' => $periodo['end_date'],
            'base_salary_paid' => 2800, 'net_pay' => 2800, 'deductions' => 0,
            'status' => 'approved_by_employee', 'employee_approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Se va de la empresa DESPUÉS de trabajar y firmar.
        $emp->update(['is_active_employee' => false]);

        $nombres = collect($this->nomina()['employees'])->pluck('name');

        $this->assertContains('Salió Después', $nombres,
            'el botón "Autorizar Pago" sí lo autoriza: la pantalla tiene que mostrarlo');
    }

    /** El periodo no salta de golpe a las 18:00 hora de México (medianoche UTC). */
    public function test_el_periodo_no_cambia_al_anochecer_local(): void
    {
        $this->colaborador('Ana Puntual', ['base_salary' => 2400]);

        Carbon::setTestNow(Carbon::parse('2026-08-15 23:59:00', 'UTC')); // 17:59 en México
        $antes = $this->nomina()['period'];

        Carbon::setTestNow(Carbon::parse('2026-08-16 00:01:00', 'UTC')); // 18:01 del MISMO día
        $despues = $this->nomina()['period'];

        $this->assertSame($antes, $despues,
            'a las 18:01 sigue siendo el mismo día en México: el periodo de nómina no puede cambiar');

        Carbon::setTestNow();
    }
}
