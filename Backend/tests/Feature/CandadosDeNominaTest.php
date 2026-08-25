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
 * CANDADOS DE NÓMINA — Fase 1 (2026-08-24).
 *
 * Dos reglas de negocio que el dueño dictó como definitivas y que el sistema YA cumplía. Que ya se
 * cumplan es justo la razón de escribir esto: una regla que nadie vigila se rompe sin que nadie se
 * entere, y contestar "eso ya está" deja al dueño tan ciego como antes de preguntar. Aquí quedan
 * como candado ejecutable: el día que alguien las rompa, truena la suite y no llega al despliegue.
 *
 *   1. El EXCESO DE COMIDA no descuenta dinero. En México no se puede descontar salario por esto
 *      sin un proceso administrativo (art. 107 LFT prohíbe multar el salario). El exceso vive como
 *      indicador de auditoría para que RRHH actúe si quiere — no como una resta automática.
 *
 *   2. El pago es POR DÍA. Quien sale y vuelve a entrar el mismo día cobra su salario diario UNA
 *      vez: las horas de todos los bloques se suman para la métrica, pero el salario base no se
 *      multiplica por haber fichado dos veces.
 */
class CandadosDeNominaTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-08-10';
    private const DOMINGO = '2026-08-16';

    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Ya transcurrida la semana que se calcula: la nómina trabaja sobre periodos cerrados.
        Carbon::setTestNow(Carbon::parse('2026-08-24 09:00:00'));

        $this->tenant = Tenant::create(['name' => 'Candados QA', 'subdomain' => 'candadosqa', 'plan' => 'enterprise', 'is_active' => true]);
        LftSetting::create([
            'tenant_id' => $this->tenant->id,
            'late_tolerance_minutes' => 10,
            'meal_tolerance_minutes' => 15,
            'rest_tolerance_minutes' => 10,
        ]);
        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@candadosqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function persona(string $nombre): Employee
    {
        $user = User::create([
            'tenant_id' => $this->tenant->id, 'name' => $nombre,
            'email' => strtolower(str_replace(' ', '', $nombre)) . '@candadosqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        return Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $nombre,
            'is_active_employee' => true, 'shiftStart' => '09:00', 'shiftEnd' => '18:00',
            'restDay' => 'Domingo', 'salario_diario' => 400, 'hire_date' => '2026-01-01',
        ]);
    }

    private function punch(Employee $emp, string $fecha, string $tipo, string $hora): void
    {
        DB::table('time_entries')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $emp->user_id,
            'date' => $fecha, 'type' => $tipo, 'time' => $hora,
            'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Jornada normal de lunes a sábado, sin incidencias. */
    private function semanaCompleta(Employee $emp, ?callable $elLunes = null): void
    {
        foreach (['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14', '2026-08-15'] as $dia) {
            if ($dia === self::LUNES && $elLunes !== null) {
                $elLunes($dia);
                continue;
            }
            $this->punch($emp, $dia, 'check_in', '09:00:00');
            $this->punch($emp, $dia, 'meal_start', '14:00:00');
            $this->punch($emp, $dia, 'meal_end', '14:30:00');
            $this->punch($emp, $dia, 'check_out', '18:00:00');
        }
    }

    private function nomina(Employee $emp): array
    {
        return app(ClockService::class)->calculatePayrollForEmployee($emp, self::LUNES, self::DOMINGO);
    }

    /**
     * REGLA 1 — El exceso de comida NO reduce el pago.
     *
     * Dos personas idénticas y la misma semana. La única diferencia: una se pasó 90 minutos de su
     * hora de comida. Su pago bruto, sus deducciones y su neto tienen que salir IGUALES; el exceso
     * aparece medido, porque sirve de evidencia, pero no toca el dinero.
     */
    public function test_el_exceso_de_comida_no_reduce_el_pago(): void
    {
        $puntual = $this->persona('Come A Tiempo');
        $this->semanaCompleta($puntual);

        $excedido = $this->persona('Se Pasa Comiendo');
        $this->semanaCompleta($excedido, function (string $dia) use ($excedido) {
            // Comida de 105 minutos sobre 60 permitidos. La tolerancia (15) es UMBRAL, no
            // franquicia: como 105 > 75, se cobra la duración menos lo permitido = 45 minutos.
            $this->punch($excedido, $dia, 'check_in', '09:00:00');
            $this->punch($excedido, $dia, 'meal_start', '14:00:00');
            $this->punch($excedido, $dia, 'meal_end', '15:45:00');
            $this->punch($excedido, $dia, 'check_out', '18:00:00');
        });

        $a = $this->nomina($puntual);
        $b = $this->nomina($excedido);

        $this->assertSame(
            45,
            (int) $b['incidents']['meal_excess_minutes'],
            'el exceso tiene que MEDIRSE: es evidencia para RRHH'
        );
        $this->assertSame(0, (int) $a['incidents']['meal_excess_minutes']);

        $this->assertSame(
            round((float) $a['salary']['gross'], 2),
            round((float) $b['salary']['gross'], 2),
            'el exceso de comida movió el salario BRUTO'
        );
        $this->assertSame(
            round((float) $a['deductions_breakdown']['total'], 2),
            round((float) $b['deductions_breakdown']['total'], 2),
            'el exceso de comida generó una DEDUCCIÓN'
        );
        $this->assertSame(
            round((float) $a['salary']['net'], 2),
            round((float) $b['salary']['net'], 2),
            'el exceso de comida movió el NETO a pagar'
        );
    }

    /** Ni siquiera un exceso brutal —cuatro horas— puede tocar el dinero. */
    public function test_ni_un_exceso_enorme_de_comida_toca_el_dinero(): void
    {
        $puntual = $this->persona('Referencia');
        $this->semanaCompleta($puntual);

        $enorme = $this->persona('Cuatro Horas');
        $this->semanaCompleta($enorme, function (string $dia) use ($enorme) {
            $this->punch($enorme, $dia, 'check_in', '09:00:00');
            $this->punch($enorme, $dia, 'meal_start', '13:00:00');
            $this->punch($enorme, $dia, 'meal_end', '17:00:00');
            $this->punch($enorme, $dia, 'check_out', '18:00:00');
        });

        $this->assertSame(
            round((float) $this->nomina($puntual)['salary']['net'], 2),
            round((float) $this->nomina($enorme)['salary']['net'], 2)
        );
    }

    /**
     * REGLA 2 — Salir y volver a entrar el mismo día se paga UNA vez.
     *
     * El pago es por DÍA: las horas de los dos bloques se suman para la métrica de "Horas
     * Trabajadas", pero el salario base no se multiplica por haber fichado dos veces. No existe un
     * cálculo por horas para el salario base y este candado impide que alguien lo invente.
     */
    public function test_multiples_fichajes_suman_horas_pero_no_multiplican_salario_base(): void
    {
        $sencillo = $this->persona('Una Sola Entrada');
        $this->semanaCompleta($sencillo);

        $partido = $this->persona('Turno Partido');
        $this->semanaCompleta($partido, function (string $dia) use ($partido) {
            // Sale a la 13:00 (cita médica) y regresa a las 15:00 hasta las 18:00.
            $this->punch($partido, $dia, 'check_in', '09:00:00');
            $this->punch($partido, $dia, 'check_out', '13:00:00');
            $this->punch($partido, $dia, 'check_in', '15:00:00');
            $this->punch($partido, $dia, 'check_out', '18:00:00');
        });

        $a = $this->nomina($sencillo);
        $b = $this->nomina($partido);

        // --- el dinero NO se multiplica -------------------------------------------------
        $this->assertSame(
            round((float) $a['salary']['gross'], 2),
            round((float) $b['salary']['gross'], 2),
            'fichar dos veces el mismo día multiplicó el salario base'
        );
        $this->assertSame(400.0 * 7, round((float) $b['salary']['gross'], 2), 'el bruto es salario diario × días del periodo');
        $this->assertSame(
            round((float) $a['salary']['net'], 2),
            round((float) $b['salary']['net'], 2)
        );

        // El día del reingreso está trabajado: no puede contar como falta.
        $this->assertSame(0, (int) $b['incidents']['physical_absences']);

        // --- las HORAS sí suman los dos bloques -----------------------------------------
        $csv = $this->reporteDeHoras();

        // Lunes de la persona de turno partido: 09:00–13:00 y 15:00–18:00 = 7 horas brutas,
        // sin comida de por medio. La de una sola entrada trabaja 9 brutas menos 30 de comida.
        $this->assertStringContainsString('Turno Partido', $csv);
        $this->assertStringContainsString('7:00', $csv, 'las horas de los dos bloques tienen que sumarse');
    }

    /** Volver a entrar tampoco puede cobrarse como un segundo retardo. */
    public function test_el_reingreso_no_genera_un_segundo_retardo(): void
    {
        $emp = $this->persona('Regresa Tarde');
        $this->semanaCompleta($emp, function (string $dia) use ($emp) {
            $this->punch($emp, $dia, 'check_in', '09:00:00');
            $this->punch($emp, $dia, 'check_out', '13:00:00');
            // Regresa a las 15:40, mucho después de su hora de entrada: no es una entrada nueva.
            $this->punch($emp, $dia, 'check_in', '15:40:00');
            $this->punch($emp, $dia, 'check_out', '18:00:00');
        });

        $r = $this->nomina($emp);

        $this->assertSame(0, (int) $r['incidents']['lates'], 'el regreso de una salida no es un retardo nuevo');
        $this->assertSame(400.0 * 7, round((float) $r['salary']['gross'], 2));
    }

    private function reporteDeHoras(): string
    {
        $res = $this->actingAs($this->admin)
            ->get('/api/v1/admin/reports/horas.csv?from=' . self::LUNES . '&to=' . self::DOMINGO);
        $res->assertOk();

        return $res->streamedContent();
    }
}
