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
 * Sin sueldo capturado, el cálculo es CERO y lo dice (Regla 4, 2026-08-24).
 *
 * El motor tenía un default escondido: `base_salary ?? salary ?? 2400.00`. Quien no tenía sueldo
 * capturado viajaba en la respuesta con $2,400 dentro de `salary.base` —una cifra que nadie
 * capturó, presentada como si fuera su sueldo— y la pantalla sólo lo tapaba porque volvía a mirar
 * el expediente por su cuenta para decidir si mostrar "Pendiente". Dos fuentes de verdad para el
 * mismo hecho, y la que salía en el JSON era la inventada.
 *
 * Ahora el número no existe: sin sueldo todo sale en 0.00 y el motor DECLARA `salary.pending`.
 */
class SinSueldoCapturadoElCalculoEsCeroTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-08-10';
    private const DOMINGO = '2026-08-16';

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-24 09:00:00'));
        $this->tenant = Tenant::create(['name' => 'Sueldo QA', 'subdomain' => 'sueldoqa', 'plan' => 'enterprise', 'is_active' => true]);
        LftSetting::create(['tenant_id' => $this->tenant->id, 'late_tolerance_minutes' => 10]);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@sueldoqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
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
            'email' => strtolower(str_replace(' ', '', $nombre)) . '@sueldoqa.test',
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

    public function test_sin_ningun_sueldo_capturado_todo_sale_en_cero(): void
    {
        $emp = $this->persona('Recien Contratada', ['base_salary' => null, 'salary' => null, 'salario_diario' => null]);

        $r = $this->nomina($emp);

        $this->assertSame(0.0, round((float) $r['salary']['base'], 2), 'aquí es donde vivía el $2,400 fantasma');
        $this->assertSame(0.0, round((float) $r['salary']['daily'], 2));
        $this->assertSame(0.0, round((float) $r['salary']['gross'], 2));
        $this->assertSame(0.0, round((float) $r['salary']['net'], 2));
        $this->assertTrue($r['salary']['pending'], 'el motor tiene que declarar que no hay sueldo');
    }

    public function test_un_sueldo_en_cero_cuenta_igual_que_no_tener_ninguno(): void
    {
        $emp = $this->persona('Sueldo En Cero', ['base_salary' => 0, 'salary' => 0, 'salario_diario' => 0]);

        $r = $this->nomina($emp);

        $this->assertSame(0.0, round((float) $r['salary']['base'], 2));
        $this->assertTrue($r['salary']['pending']);
    }

    public function test_quien_si_tiene_sueldo_no_se_marca_como_pendiente(): void
    {
        $conDiario = $this->persona('Con Diario', ['salario_diario' => 400]);
        $porLaLegada = $this->persona('Por La Legada', ['base_salary' => 2100]);

        $a = $this->nomina($conDiario);
        $b = $this->nomina($porLaLegada);

        $this->assertFalse($a['salary']['pending']);
        $this->assertSame(400.0, round((float) $a['salary']['daily'], 2));

        $this->assertFalse($b['salary']['pending']);
        $this->assertSame(2100.0, round((float) $b['salary']['base'], 2));
        // La fórmula legada /6 sigue INTACTA: este cambio no toca el dinero de nadie.
        $this->assertSame(350.0, round((float) $b['salary']['daily'], 2));
    }

    /** La pantalla ya no vuelve a deducirlo: consume lo que declara el motor. */
    public function test_la_prenomina_marca_pendiente_a_quien_el_motor_marco(): void
    {
        $this->persona('Recien Contratada', ['base_salary' => null, 'salary' => null]);
        $this->persona('Con Diario', ['salario_diario' => 400]);

        // El periodo de la prenomina se pide con start_date/end_date (no from/to, que son los
        // de los reportes operativos).
        $res = $this->actingAs($this->admin)->getJson(
            '/api/v1/admin/payroll?start_date=' . self::LUNES . '&end_date=' . self::DOMINGO
        );
        $res->assertOk();

        $porNombre = collect($res->json('employees'))->keyBy('name');

        $this->assertTrue((bool) $porNombre['Recien Contratada']['salary_pending']);
        $this->assertSame(0.0, round((float) $porNombre['Recien Contratada']['base'], 2));
        $this->assertFalse((bool) $porNombre['Con Diario']['salary_pending']);
    }
}
