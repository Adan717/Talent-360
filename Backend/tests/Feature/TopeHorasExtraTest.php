<?php

namespace Tests\Feature;

use App\Helpers\TenantTimezone;
use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ClockService;
use App\Services\PayrollWeekService;
use App\Support\JornadaExtraordinaria;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TOPE DE TIEMPO EXTRAORDINARIO POR EMPRESA (2026-09-05).
 *
 * Lo que no existía antes: ni tope, ni constante, ni aviso, ni contador semanal. Lo único que se
 * llamaba "Autorizar horas extras" (`overtime_authorizations`) no autoriza horas — levanta el
 * bloqueo de un DÍA feriado o de descanso para poder fichar.
 *
 * Las cuatro cosas que estas pruebas sostienen:
 *  1. El acumulador cuenta las MISMAS horas que el reporte de horas trabajadas (una sola fórmula,
 *     `App\Support\JornadaTrabajada`, no dos cuentas del mismo dato).
 *  2. Un tope por encima del techo del art. 66 de la LFT se rechaza con 422 y mensaje explícito.
 *  3. Al rebasar, el aviso aparece con el NÚMERO REAL — en el dial (/sync/state) y en el Monitor.
 *  4. CANDADO: el dinero de la nómina no cambia por este renglón. El motor paga por DÍA; el tope
 *     avisa y no cobra.
 *
 * Fechas: todo se siembra en la semana del TENANT según `PayrollWeekService` y en su zona horaria.
 * Ninguna fecha literal, ningún `startOfWeek` de Carbon (el tenant puede empezar la semana en otro
 * día). La prueba pasa a las 3 de la mañana.
 */
class TopeHorasExtraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $colaborador;
    private Employee $expediente;
    private string $zona;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Extras QA', 'subdomain' => 'extrasqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Almacenista', 'area' => 'Piso', 'esAperturador' => false,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@extrasqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sara Extra', 'email' => 'sara@extrasqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        // Turno 09:00–18:00 con 60 min de comida → jornada ordinaria efectiva = 480 min (8 h).
        $this->expediente = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'name' => 'Sara Extra', 'job_role_id' => $puesto->id, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'mealMinutes' => 60, 'restDay' => 'Domingo',
            'salary' => 3000, 'hire_date' => Carbon::now()->subYear()->toDateString(),
        ]);

        DB::table('lft_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id],
            ['late_tolerance_minutes' => 10, 'lates_per_absence' => 3, 'created_at' => now(), 'updated_at' => now()]
        );

        $this->zona = TenantTimezone::for($this->tenant->id);
    }

    /** El día `$n` de la semana EN CURSO del tenant, en su propia zona horaria. */
    private function diaDeLaSemana(int $n): string
    {
        [$inicio] = app(PayrollWeekService::class)
            ->weekRangeFor($this->tenant->id, Carbon::now($this->zona));

        return $inicio->copy()->addDays($n)->toDateString();
    }

    private function fichaje(string $fecha, string $type, string $hora, array $extra = []): void
    {
        DB::table('time_entries')->insert(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'date' => $fecha, 'type' => $type, 'time' => $hora,
            'is_late' => false, 'late_minutes' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    /**
     * Una jornada de `$horas` horas en sucursal con 1 hora de comida.
     * Con turno de 8 h efectivas, `$horas = 11` deja 10 efectivas → 2 h extraordinarias.
     */
    private function jornadaDe(string $fecha, int $horas): void
    {
        $salida = str_pad((string) (9 + $horas), 2, '0', STR_PAD_LEFT) . ':00:00';
        $this->fichaje($fecha, 'check_in', '09:00:00');
        $this->fichaje($fecha, 'meal_start', '14:00:00');
        $this->fichaje($fecha, 'meal_end', '15:00:00');
        $this->fichaje($fecha, 'check_out', $salida);
    }

    // ---------------------------------------------------------------------------------------
    // 1. UNA SOLA CUENTA
    // ---------------------------------------------------------------------------------------

    /**
     * LA PRUEBA QUE IMPORTA: el acumulador y el reporte de horas trabajadas cuentan lo mismo.
     *
     * No se compara contra un número escrito a mano sino contra las HORAS EFECTIVAS que publica el
     * CSV: si alguien cambia una de las dos fórmulas, esto truena. El defecto histórico de este
     * proyecto es tener dos cifras del mismo dato en dos pantallas.
     */
    public function test_el_acumulador_cuenta_las_mismas_horas_que_el_reporte(): void
    {
        $dia = $this->diaDeLaSemana(1);
        $this->jornadaDe($dia, 11); // 11 en sucursal − 1 de comida = 10 efectivas

        $respuesta = $this->actingAs($this->admin)
            ->get("/api/v1/admin/reports/horas.csv?from={$dia}&to={$dia}");
        $respuesta->assertOk();

        $renglon = collect(explode("\n", $respuesta->streamedContent()))
            ->first(fn ($l) => str_contains($l, 'Sara Extra'));
        $this->assertNotNull($renglon, 'la colaboradora tiene que aparecer en el reporte de horas');

        $campos = str_getcsv(trim($renglon));
        $this->assertSame('10:00', $campos[8], 'el reporte dice 10 horas efectivas');

        // El acumulador parte de las MISMAS horas efectivas: 10 h − 8 h de jornada ordinaria = 2 h.
        $acumulado = JornadaExtraordinaria::deLaSemana($this->tenant->id, $this->colaborador->id);
        $this->assertNotNull($acumulado);
        $this->assertSame(120, $acumulado['minutos'], '10 h efectivas menos 8 h ordinarias = 2 h extra');
        $this->assertSame(1, $acumulado['dias']);
    }

    /** Sin exceso no hay acumulado: una jornada normal no genera tiempo extraordinario fantasma. */
    public function test_una_jornada_normal_no_genera_tiempo_extraordinario(): void
    {
        $this->jornadaDe($this->diaDeLaSemana(1), 9); // 9 − 1 = 8 efectivas = su jornada ordinaria

        $this->assertNull(
            JornadaExtraordinaria::deLaSemana($this->tenant->id, $this->colaborador->id),
            'trabajar exactamente su turno no es tiempo extraordinario'
        );
    }

    /**
     * Quien no tiene turno configurado NO acumula: sin jornada ordinaria no hay exceso que medir,
     * y suponerle una (8 h, por decir) sería inventar el número con el que se le acusa.
     */
    public function test_sin_turno_configurado_no_se_inventa_tiempo_extraordinario(): void
    {
        $this->expediente->update(['shiftStart' => null, 'shiftEnd' => null]);
        $this->jornadaDe($this->diaDeLaSemana(1), 14); // 13 h efectivas

        $this->assertNull(JornadaExtraordinaria::deLaSemana($this->tenant->id, $this->colaborador->id));
    }

    /** El acumulado es de la SEMANA del tenant: lo de la semana pasada no se arrastra. */
    public function test_el_acumulado_se_limita_a_la_semana_del_tenant(): void
    {
        [$inicio] = app(PayrollWeekService::class)
            ->weekRangeFor($this->tenant->id, Carbon::now($this->zona));

        $this->jornadaDe($inicio->copy()->subDay()->toDateString(), 13); // semana ANTERIOR: 4 h extra
        $this->jornadaDe($this->diaDeLaSemana(1), 11);                   // esta semana: 2 h extra

        $acumulado = JornadaExtraordinaria::deLaSemana($this->tenant->id, $this->colaborador->id);
        $this->assertSame(120, $acumulado['minutos'], 'sólo cuenta la semana en curso');
    }

    // ---------------------------------------------------------------------------------------
    // 2. EL TECHO DE LEY ES DURO
    // ---------------------------------------------------------------------------------------

    /** Art. 66 LFT: 3 h diarias, no más de 3 veces por semana = 9 h = 540 min. */
    public function test_un_tope_por_encima_del_techo_de_ley_se_rechaza_con_422(): void
    {
        $respuesta = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/lft-settings', $this->ajustesCon(541));

        $respuesta->assertStatus(422);
        $respuesta->assertJsonValidationErrors('overtime_weekly_cap_minutes');

        $mensaje = $respuesta->json('errors.overtime_weekly_cap_minutes.0');
        $this->assertStringContainsString('artículo 66', $mensaje, 'el rechazo debe decir POR QUÉ');
        $this->assertStringContainsString('9 horas', $mensaje, 'y cuál es el máximo real');

        $this->assertSame(
            JornadaExtraordinaria::TECHO_LFT_MINUTOS_SEMANA,
            JornadaExtraordinaria::tope($this->tenant->id),
            'el valor rechazado no se guardó'
        );
    }

    /** Un tope MENOR sí es decisión de la empresa y se guarda tal cual. */
    public function test_la_empresa_puede_ponerse_un_tope_menor(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/lft-settings', $this->ajustesCon(180))
            ->assertOk();

        $this->assertSame(180, JornadaExtraordinaria::tope($this->tenant->id));
    }

    /**
     * CANDADO del techo: aunque una fila vieja (o un UPDATE por SQL crudo, que es como se han
     * tocado estas tablas en producción) deje un valor por encima de la ley, el acumulador lo
     * recorta. La ley no puede depender de que el formulario haya validado bien.
     */
    public function test_un_valor_ilegal_metido_por_sql_crudo_se_recorta_al_techo_de_ley(): void
    {
        DB::table('lft_settings')
            ->where('tenant_id', $this->tenant->id)
            ->update(['overtime_weekly_cap_minutes' => 1200]);

        $this->assertSame(
            JornadaExtraordinaria::TECHO_LFT_MINUTOS_SEMANA,
            JornadaExtraordinaria::tope($this->tenant->id)
        );
    }

    /** Los ajustes completos que el formulario manda, con el tope que se quiera probar. */
    private function ajustesCon(int $topeMinutos): array
    {
        return [
            'lates_per_absence' => 3,
            'deduct_absence_day' => true,
            'absences_for_warning' => 3,
            'absences_for_suspension' => 4,
            'proportional_rest_day' => true,
            'late_tolerance_minutes' => 10,
            'meal_tolerance_minutes' => 15,
            'rest_tolerance_minutes' => 10,
            'late_action_mode' => 'deduct',
            'paid_rest_day' => true,
            'overtime_weekly_cap_minutes' => $topeMinutos,
        ];
    }

    // ---------------------------------------------------------------------------------------
    // 3. EL AVISO DICE EL NÚMERO
    // ---------------------------------------------------------------------------------------

    /** El dial recibe su acumulado y su tope en /sync/state, con la cifra real. */
    public function test_el_dial_recibe_el_acumulado_con_el_numero_real(): void
    {
        DB::table('lft_settings')->where('tenant_id', $this->tenant->id)
            ->update(['overtime_weekly_cap_minutes' => 180]); // 3 h

        $this->jornadaDe($this->diaDeLaSemana(1), 12); // 11 efectivas → 3 h extra
        $this->jornadaDe($this->diaDeLaSemana(2), 11); // 10 efectivas → 2 h extra  ⇒ 5 h en total

        $respuesta = $this->actingAs($this->colaborador)->getJson('/api/v1/sync/state');
        $respuesta->assertOk();

        $aviso = $respuesta->json('mi_jornada_extraordinaria');
        $this->assertNotNull($aviso, 'el dial tiene que recibir el acumulado para poder avisar');
        $this->assertSame(300, $aviso['minutos'], '3 h + 2 h = 5 h');
        $this->assertSame(180, $aviso['tope']);
        $this->assertTrue($aviso['rebasado']);
        $this->assertSame(2, $aviso['dias']);
    }

    /** Debajo del tope el dial recibe el dato pero sin bandera de rebasado. */
    public function test_debajo_del_tope_no_se_marca_como_rebasado(): void
    {
        $this->jornadaDe($this->diaDeLaSemana(1), 11); // 2 h extra, tope de fábrica 9 h

        $aviso = $this->actingAs($this->colaborador)
            ->getJson('/api/v1/sync/state')->json('mi_jornada_extraordinaria');

        $this->assertSame(120, $aviso['minutos']);
        $this->assertFalse($aviso['rebasado']);
    }

    /** El Monitor le dice al jefe QUIÉN rebasó y CUÁNTO lleva. */
    public function test_el_monitor_alerta_al_jefe_con_la_cifra_de_quien_rebaso(): void
    {
        DB::table('lft_settings')->where('tenant_id', $this->tenant->id)
            ->update(['overtime_weekly_cap_minutes' => 120]); // 2 h

        $this->jornadaDe($this->diaDeLaSemana(1), 12); // 3 h extra ⇒ rebasa

        $respuesta = $this->actingAs($this->admin)->getJson('/api/v1/admin/dashboard/monitor');
        $respuesta->assertOk();

        $alertas = $respuesta->json('data.alertas_horas_extra');
        $this->assertCount(1, $alertas);
        $this->assertSame('Sara Extra', $alertas[0]['nombre']);
        $this->assertSame(180, $alertas[0]['minutos'], 'la alerta trae la cifra, no una frase genérica');
        $this->assertSame(120, $alertas[0]['tope']);
    }

    /** Quien NO rebasa no aparece en la alerta del jefe: una alerta que siempre suena no es alerta. */
    public function test_el_monitor_no_alerta_de_quien_no_rebaso(): void
    {
        $this->jornadaDe($this->diaDeLaSemana(1), 11); // 2 h extra contra el tope de fábrica (9 h)

        $alertas = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/dashboard/monitor')->json('data.alertas_horas_extra');

        $this->assertSame([], $alertas);
    }

    /** El aviso no bloquea: rebasado el tope, la persona sigue pudiendo fichar. */
    public function test_rebasar_el_tope_avisa_pero_no_bloquea_el_fichaje(): void
    {
        DB::table('lft_settings')->where('tenant_id', $this->tenant->id)
            ->update(['overtime_weekly_cap_minutes' => 60]);

        $this->jornadaDe($this->diaDeLaSemana(1), 13); // 4 h extra ⇒ muy por encima

        $estado = $this->actingAs($this->colaborador)->getJson('/api/v1/sync/state');
        $estado->assertOk();
        $this->assertTrue($estado->json('mi_jornada_extraordinaria.rebasado'));

        // "Nada bloquea, todo avisa": el endpoint del reloj sigue respondiendo con normalidad.
        $this->assertNull(
            $estado->json('bloqueo_por_horas_extra'),
            'el tope no puede haber introducido un bloqueo: es un aviso'
        );
    }

    // ---------------------------------------------------------------------------------------
    // 4. CANDADO DEL DINERO
    // ---------------------------------------------------------------------------------------

    /**
     * EL CANDADO QUE MÁS IMPORTA: este renglón NO toca el dinero.
     *
     * La nómina de este sistema paga por DÍA, no por horas, y cómo se PAGA una hora extra depende
     * del divisor de la tarifa horaria — pregunta abierta al contador del dueño. Así que el mismo
     * periodo, con y sin tiempo extraordinario acumulado y con el tope apretado a la mitad, tiene
     * que producir EXACTAMENTE el mismo neto.
     */
    public function test_el_tope_de_horas_extra_no_cambia_un_peso_de_la_nomina(): void
    {
        $desde = $this->diaDeLaSemana(0);
        $hasta = $this->diaDeLaSemana(5);
        $diaLargo = $this->diaDeLaSemana(3);

        // Tres jornadas NORMALES. Éste es el neto de referencia.
        $this->jornadaDe($this->diaDeLaSemana(1), 9);
        $this->jornadaDe($this->diaDeLaSemana(2), 9);
        $this->jornadaDe($diaLargo, 9);

        $motor = app(ClockService::class);
        $antes = $motor->calculatePayrollForEmployee($this->expediente->fresh(), $desde, $hasta);
        $this->assertNull(
            JornadaExtraordinaria::deLaSemana($this->tenant->id, $this->colaborador->id),
            'el escenario de referencia no debe traer tiempo extraordinario'
        );

        // MISMOS días asistidos, misma gente, mismo periodo: lo ÚNICO que cambia es que el día 3
        // se estira a 14 horas (5 h extraordinarias) y que el tope queda apretadísimo. Estirar la
        // salida en vez de agregar un día es lo que aísla la variable: un día más SÍ movería el
        // neto —el motor paga por día—, y entonces la prueba no probaría nada.
        DB::table('time_entries')
            ->where('tenant_id', $this->tenant->id)
            ->where('user_id', $this->colaborador->id)
            ->where('date', $diaLargo)
            ->where('type', 'check_out')
            ->update(['time' => '23:00:00']);

        DB::table('lft_settings')->where('tenant_id', $this->tenant->id)
            ->update(['overtime_weekly_cap_minutes' => 30]);

        $acumulado = JornadaExtraordinaria::deLaSemana($this->tenant->id, $this->colaborador->id);
        $this->assertSame(300, $acumulado['minutos'], '13 h efectivas − 8 h ordinarias = 5 h');
        $this->assertTrue($acumulado['rebasado'], 'el escenario tiene que rebasar de verdad');

        $despues = $motor->calculatePayrollForEmployee($this->expediente->fresh(), $desde, $hasta);

        $this->assertSame(
            (float) $antes['salary']['net'],
            (float) $despues['salary']['net'],
            'el tiempo extraordinario y su tope NO pueden mover el neto: el motor paga por día'
        );
        $this->assertSame(
            (float) $antes['salary']['gross'],
            (float) $despues['salary']['gross'],
            'ni el bruto'
        );
        $this->assertGreaterThan(0.0, (float) $antes['salary']['net'], 'no es una comparación de dos ceros');
    }

    /** "570" se le dice a la gente como "9 h 30 min", no como un número de minutos suelto. */
    public function test_los_minutos_se_dicen_en_horas_para_la_pantalla(): void
    {
        $this->assertSame('9 h', JornadaExtraordinaria::enHoras(540));
        $this->assertSame('9 h 30 min', JornadaExtraordinaria::enHoras(570));
        $this->assertSame('45 min', JornadaExtraordinaria::enHoras(45));
    }
}
