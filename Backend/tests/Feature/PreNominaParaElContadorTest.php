<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ReferenciaFiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EL REPORTE PARA EL CONTADOR (Plan B, 2026-09-08).
 *
 * Lo que estas pruebas protegen no es la aritmética fiscal —de eso se encarga
 * `ReferenciaFiscalTest`, con los casos calculados a mano— sino las tres cosas que hacen que
 * un reporte de dinero sea usable o peligroso:
 *
 *  1. Que CUADRE consigo mismo (percepciones = gravado + exento = el neto que ya se pagó). Es
 *     lo primero que revisa un contador, y este proyecto ya tuvo un ticket que no cuadraba.
 *  2. Que DECLARE su alcance: cifras de referencia, el sistema no timbra. Sin esa frase, el
 *     documento se parece demasiado a un recibo fiscal.
 *  3. Que el candado del dinero siga puesto (`permission:manage_payroll`) y que no invente
 *     nada sobre los recibos que no puede leer.
 */
class PreNominaParaElContadorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private Employee $expediente;
    private string $inicio;
    private string $fin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Contador QA', 'subdomain' => 'contaqa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso',
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@contaqa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Pedro', 'email' => 'pedro@contaqa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $this->inicio = now()->subDays(20)->toDateString();
        $this->fin = now()->subDays(14)->toDateString();

        $this->expediente = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $colaborador->id,
            'name' => 'Pedro', 'job_role_id' => $puesto->id, 'is_active_employee' => true,
            // Dos años cumplidos al inicio del periodo: 14 días de vacaciones → factor 1.0507.
            'hire_date' => now()->subYears(2)->subDays(30)->toDateString(),
        ]);
    }

    /** Un recibo con todas sus columnas, tal como lo guarda `DesgloseDeNomina`. */
    private function recibo(array $cambios = []): void
    {
        DB::table('weekly_payrolls')->insert(array_merge([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->expediente->id,
            'start_date' => $this->inicio,
            'end_date' => $this->fin,
            'base_salary_paid' => 3500,
            'gross_pay' => 4500,          // 500 diarios × 7 días + 1,000 de prima de festivo
            'holiday_bonus_pay' => 1000,
            'punctuality_bonus' => 200,
            'opening_bonus' => 0,
            'deductions' => 500,
            'deduction_absences' => 500,
            'deduction_rest_day' => 0,
            'deduction_lates' => 0,
            'daily_salary' => 500,
            'net_pay' => 4200,            // 4,500 − 500 + 200
            'job_role_title_at_time' => 'Cajero',
            'job_role_area_at_time' => 'Piso',
            'status' => 'approved_by_employee',
            'employee_approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $cambios));
    }

    private function csv(): string
    {
        $r = $this->actingAs($this->admin)->get(
            '/api/v1/admin/reports/prenomina_contador.csv?from=' . $this->inicio . '&to=' . now()->toDateString()
        );
        $r->assertOk();

        return $r->streamedContent();
    }

    /** La fila de datos de Pedro, ya partida en campos. */
    private function renglon(string $csv, string $quien = 'Pedro'): array
    {
        $linea = collect(explode("\n", $csv))
            ->first(fn ($l) => str_starts_with($l, $this->inicio) && str_contains($l, $quien));

        $this->assertNotNull($linea, "el reporte no trae el renglón de {$quien}");

        return str_getcsv(trim($linea));
    }

    /**
     * LA CUENTA CUADRA, y cada peso está de un solo lado: percepciones = gravado + exento, y el
     * total de percepciones es el mismo neto que el recibo ya pagó (no aparece dinero nuevo).
     */
    public function test_el_reporte_cuadra_consigo_mismo_y_con_el_recibo(): void
    {
        $this->recibo();
        $campos = $this->renglon($this->csv());

        [$sueldo, $prima, $bonos, $total, $gravado, $exento] = [
            (float) $campos[5], (float) $campos[6], (float) $campos[7],
            (float) $campos[8], (float) $campos[9], (float) $campos[10],
        ];

        $this->assertSame(7, (int) $campos[2], 'el periodo son 7 días');
        $this->assertSame(3000.0, $sueldo, '(bruto 4,500 − prima 1,000) − 500 de descuentos');
        $this->assertSame(1000.0, $prima);
        $this->assertSame(200.0, $bonos);

        $this->assertEqualsWithDelta($total, $sueldo + $prima + $bonos, 0.01, 'las tres partes suman el total');
        $this->assertEqualsWithDelta($total, $gravado + $exento, 0.01, 'gravado + exento = percepciones');
        $this->assertEqualsWithDelta(4200.0, $total, 0.01, 'y el total es el NETO que el recibo ya pagó');
        $this->assertEqualsWithDelta(4200.0, (float) $campos[19], 0.01, 'la columna del neto del recibo');

        // La mitad de la prima de festivo va exenta (LISR art. 93 fr. I), muy por debajo del
        // tope de 5 UMA por semana.
        $this->assertSame(500.0, $exento);
    }

    /**
     * El SBC sale de la antigüedad del expediente, y el ISR y el IMSS del reporte son los que
     * devuelve la tabla fiscal para ESE gravado y ESOS días: si el controlador se equivocara de
     * base o de periodo, aquí se separan.
     */
    public function test_el_sbc_el_isr_y_el_imss_salen_de_la_tabla_vigente(): void
    {
        $this->recibo();
        $campos = $this->renglon($this->csv());

        $this->assertSame(2, (int) $campos[12], 'dos años cumplidos al inicio del periodo');
        $this->assertSame(1.0507, (float) $campos[13], 'factor de integración con 14 días de vacaciones');
        $this->assertEqualsWithDelta(525.35, (float) $campos[14], 0.01, '500 diarios × 1.0507');

        $esperadoIsr = ReferenciaFiscal::isrDelPeriodo(3700.0, 7);
        $esperadoImss = ReferenciaFiscal::cuotaObreraImss(525.35, 7, false);

        $this->assertEqualsWithDelta($esperadoIsr['causado'], (float) $campos[15], 0.02, 'ISR causado');
        $this->assertEqualsWithDelta($esperadoIsr['subsidio'], (float) $campos[16], 0.02, 'subsidio al empleo');
        $this->assertEqualsWithDelta($esperadoIsr['retencion'], (float) $campos[17], 0.02, 'ISR a retener');
        $this->assertEqualsWithDelta($esperadoImss['total'], (float) $campos[18], 0.02, 'cuota obrera del IMSS');

        // Neto estimado = lo que se pagó menos las retenciones de referencia.
        $this->assertEqualsWithDelta(
            4200.0 - $esperadoIsr['retencion'] - $esperadoImss['total'],
            (float) $campos[20],
            0.02
        );
    }

    /**
     * Al salario mínimo cambian DOS cosas y las dos mueven dinero: la cuota obrera la cubre el
     * patrón (LSS art. 36) y la prima de festivo va 100 % exenta (LISR art. 93 fr. I). En la
     * plantilla de una tienda eso no es un caso raro: es la mayoría.
     */
    public function test_al_salario_minimo_no_se_retiene_imss_y_se_dice_por_que(): void
    {
        $this->recibo([
            'daily_salary' => ReferenciaFiscal::SALARIO_MINIMO_GENERAL,
            'gross_pay' => 2205.28, 'holiday_bonus_pay' => 400, 'punctuality_bonus' => 0,
            'deductions' => 0, 'deduction_absences' => 0, 'net_pay' => 2205.28,
        ]);

        $campos = $this->renglon($this->csv());

        $this->assertSame(0.0, (float) $campos[18], 'el trabajador de salario mínimo no aporta al IMSS');
        $this->assertSame(400.0, (float) $campos[10], 'y su prima de festivo va toda exenta');
        $this->assertStringContainsString('Salario minimo', $campos[21]);
        $this->assertStringContainsString('LSS art. 36', $campos[21]);
    }

    /**
     * El documento DECLARA lo que es. Sin estas frases se parece demasiado a un recibo fiscal,
     * que es justo lo que la decisión del dueño (la nómina orienta, no timbra) prohíbe.
     */
    public function test_declara_que_es_referencia_y_que_el_sistema_no_timbra(): void
    {
        $this->recibo();
        $csv = $this->csv();

        $this->assertStringContainsString('CIFRAS DE REFERENCIA', $csv);
        $this->assertStringContainsString('no sustituyen el calculo del contador', $csv);
        $this->assertStringContainsString('NO TIMBRA', $csv);
        // Y de qué ejercicio son las tablas, con su fuente: caducan cada año.
        $this->assertStringContainsString('ejercicio ' . ReferenciaFiscal::VIGENCIA, $csv);
        $this->assertStringContainsString('Anexo 8', $csv);
        $this->assertStringContainsString('solo la CUOTA OBRERA', $csv);
    }

    /**
     * Lo que no se puede clasificar NO se inventa: un recibo anterior al desglose (2026-08-16)
     * no trae sus partes, así que queda fuera y se declara con su importe. Un borrador tampoco
     * entra: se reescribe cada noche y no es dinero comprometido.
     */
    public function test_no_entran_los_borradores_ni_los_recibos_sin_desglose(): void
    {
        $this->recibo();
        $this->recibo([
            'start_date' => now()->subDays(13)->toDateString(),
            'end_date' => now()->subDays(7)->toDateString(),
            'status' => 'draft', 'employee_approved_at' => null, 'net_pay' => 999.99,
        ]);
        $this->recibo([
            'start_date' => now()->subDays(6)->toDateString(),
            'end_date' => now()->toDateString(),
            'gross_pay' => null, 'holiday_bonus_pay' => null, 'punctuality_bonus' => null,
            'opening_bonus' => null, 'deduction_absences' => null, 'deduction_rest_day' => null,
            'deduction_lates' => null, 'daily_salary' => null, 'net_pay' => 1234.56,
        ]);

        $csv = $this->csv();

        $this->assertStringNotContainsString('999.99', $csv, 'un borrador no es dinero comprometido');
        $this->assertStringContainsString('1 recibo(s) de este periodo son anteriores al desglose', $csv);
        $this->assertStringContainsString('1,234.56', $csv, 'dice cuánto quedó fuera en vez de repartirlo a ojo');

        // Sólo queda una fila de datos: la del recibo completo.
        $filas = collect(explode("\n", $csv))->filter(fn ($l) => str_starts_with($l, $this->inicio));
        $this->assertCount(1, $filas);
    }

    /** Trae sueldos: mismo candado que la pre-nómina histórica y el costo por puesto. */
    public function test_exige_la_capacidad_de_nomina(): void
    {
        $supervisor = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sup', 'email' => 'sup@contaqa.test',
            'password' => bcrypt('x'), 'role' => 'supervisor',
        ]);

        $this->actingAs($supervisor)->getJson('/api/v1/admin/reports/prenomina_contador.csv')->assertStatus(403);
        $this->actingAs($this->admin)->get('/api/v1/admin/reports/prenomina_contador.csv')->assertOk();
        $this->assertTrue(\App\Support\CatalogoDeReportes::esDeNomina('prenomina_contador'));
    }

    /** El recibo del trabajador NO cambia por este reporte: aquí no se escribe nada. */
    public function test_el_reporte_no_toca_el_recibo(): void
    {
        $this->recibo();
        $antes = DB::table('weekly_payrolls')->where('tenant_id', $this->tenant->id)->get()->toArray();

        $this->csv();

        $this->assertEquals($antes, DB::table('weekly_payrolls')->where('tenant_id', $this->tenant->id)->get()->toArray());
    }
}
