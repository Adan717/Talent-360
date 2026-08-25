<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LftSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use App\Support\SalarioDiarioCalculator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El salario diario sale de lo que el expediente DECLARA (2026-08-24).
 *
 * El `/ 6.0` salió del motor de nómina. Arrastraba dos errores encima del otro: aun siendo semanal
 * la práctica LFT reparte entre 7 (el séptimo día es descanso pagado, art. 69), y —el grande— el
 * monto capturado no declaraba de qué periodo era, así que el motor SUPONÍA semanal. Un sueldo
 * mensual daba un diario casi cinco veces mayor, y sobre el diario se calculan IMSS, aguinaldo,
 * prima vacacional e indemnizaciones.
 *
 * Lo que esta prueba fija, y es lo más importante del cambio: **al expediente legado no se le
 * mueve un peso**. Se conserva su resultado histórico y se marca como pendiente de recaptura.
 * Corregir la fórmula debajo de quien ya cobra con ella no es una migración: es un recorte
 * silencioso.
 */
class SalarioDiarioPorPeriodicidadTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-08-10';
    private const DOMINGO = '2026-08-16';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-24 09:00:00'));
        $this->tenant = Tenant::create(['name' => 'Periodicidad QA', 'subdomain' => 'periodqa', 'plan' => 'enterprise', 'is_active' => true]);
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function persona(string $nombre, array $sueldo): Employee
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '', $nombre)) . '@periodqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'restDay' => 'Domingo', 'hire_date' => '2026-01-01',
        ], $sueldo));
    }

    private function nomina(Employee $emp): array
    {
        return app(ClockService::class)->calculatePayrollForEmployee($emp, self::LUNES, self::DOMINGO);
    }

    // --------------------------------------------------- lo que NO se debe mover

    /**
     * EL CANDADO DE LA RETROCOMPATIBILIDAD. Un expediente legado —sueldo capturado, sin declarar
     * periodicidad— tiene que seguir dando EXACTAMENTE el mismo diario que daba el `/6`.
     */
    public function test_al_expediente_legado_no_se_le_mueve_un_peso(): void
    {
        $legado = $this->persona('Legado', ['base_salary' => 2100]);

        $r = $this->nomina($legado);

        $this->assertSame(350.0, round((float) $r['salary']['daily'], 2), '2100/6 = 350, el resultado histórico');
        $this->assertSame(2100.0, round((float) $r['salary']['base'], 2));
        $this->assertSame(350.0 * 7, round((float) $r['salary']['gross'], 2));
    }

    /** Pero SÍ se declara que ese número salió de una suposición, para que alguien lo recapture. */
    public function test_el_legado_queda_marcado_como_pendiente_de_recaptura(): void
    {
        $legado = $this->persona('Legado', ['base_salary' => 2100]);

        $r = $this->nomina($legado);

        $this->assertTrue($r['salary']['periodicity_pending'], 'nadie declaró de qué periodo es ese sueldo');
        $this->assertNull($r['salary']['periodicity']);
        $this->assertFalse($r['salary']['pending'], 'sueldo sí tiene; lo que falta es su periodicidad');
    }

    /** Con salario diario declarado, la base sigue siendo el sueldo capturado (no el diario). */
    public function test_con_diario_declarado_la_base_sigue_siendo_el_sueldo_capturado(): void
    {
        $emp = $this->persona('Con Diario', ['base_salary' => 3000, 'salario_diario' => 500]);

        $r = $this->nomina($emp);

        $this->assertSame(500.0, round((float) $r['salary']['daily'], 2));
        $this->assertSame(3000.0, round((float) $r['salary']['base'], 2));
        $this->assertFalse($r['salary']['periodicity_pending']);
    }

    // --------------------------------------------------- lo que ahora sí se puede declarar

    public function test_cada_periodicidad_declarada_usa_su_divisor_real(): void
    {
        $casos = [
            // [periodicidad, monto, diario esperado]
            ['diario', 500, 500.0],
            ['semanal', 2100, 300.0],    // /7 — el séptimo día es descanso PAGADO (art. 69)
            ['quincenal', 9000, 600.0],  // /15
            ['mensual', 18000, 600.0],   // /30
        ];

        foreach ($casos as [$periodicidad, $monto, $esperado]) {
            $emp = $this->persona('Persona ' . $periodicidad, [
                'base_salary' => $monto,
                'periodicidad_captura' => $periodicidad,
            ]);

            $r = SalarioDiarioCalculator::para($emp);

            $this->assertSame($esperado, round($r['diario'], 2), "falló la periodicidad {$periodicidad}");
            $this->assertSame(SalarioDiarioCalculator::PERIODICIDAD_DECLARADA, $r['origen']);
            $this->assertFalse($r['pendiente_periodicidad']);
        }
    }

    /**
     * La diferencia que hace que esto sea un bloqueador de venta: el MISMO número capturado, leído
     * como mensual o supuesto semanal, son dos sueldos distintos por un factor de casi cinco.
     */
    public function test_el_mismo_monto_leido_como_mensual_o_supuesto_da_diarios_muy_distintos(): void
    {
        $supuesto = $this->persona('Sin Declarar', ['base_salary' => 18000]);
        $declarado = $this->persona('Declara Mensual', ['base_salary' => 18000, 'periodicidad_captura' => 'mensual']);

        $a = SalarioDiarioCalculator::para($supuesto);
        $b = SalarioDiarioCalculator::para($declarado);

        $this->assertSame(3000.0, round($a['diario'], 2), 'el supuesto histórico: 18000/6');
        $this->assertSame(600.0, round($b['diario'], 2), 'lo que de verdad gana al día si es mensual');
        $this->assertSame(5.0, round($a['diario'] / $b['diario'], 2), 'cinco veces: por eso bloquea la venta');
    }

    public function test_sin_sueldo_no_se_inventa_periodicidad_ni_monto(): void
    {
        $emp = $this->persona('Sin Sueldo', ['base_salary' => null, 'salary' => null]);

        $r = SalarioDiarioCalculator::para($emp);

        $this->assertSame(0.0, $r['diario']);
        $this->assertSame(0.0, $r['base']);
        $this->assertTrue($r['pendiente_sueldo']);
        $this->assertFalse($r['pendiente_periodicidad'], 'sin sueldo, la periodicidad no es el problema');
    }

    /** Una periodicidad basura no se cuela como válida: cae al supuesto y se marca. */
    public function test_una_periodicidad_invalida_no_se_toma_por_buena(): void
    {
        $emp = $this->persona('Basura', ['base_salary' => 2100, 'periodicidad_captura' => 'trimestral']);

        $r = SalarioDiarioCalculator::para($emp);

        $this->assertSame(SalarioDiarioCalculator::SUPUESTO_HISTORICO, $r['origen']);
        $this->assertTrue($r['pendiente_periodicidad']);
        $this->assertSame(350.0, round($r['diario'], 2), 'ante basura, el pago no cambia');
    }
}
