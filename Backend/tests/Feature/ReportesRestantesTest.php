<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\JobRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CatalogoDeReportes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los ocho reportes restantes (2026-08-13): justificantes, aperturas, comedor, academia,
 * expedientes, reclutamiento y monedero.
 *
 * La prueba que más importa NO es que cada CSV traiga filas: es que el **catálogo único**
 * y las **rutas** no se separen. Con once reportes, el modo de fallar es que el asistente
 * ofrezca un reporte cuya descarga no existe (404 en la cara del usuario), y eso sólo lo
 * caza una prueba que recorra el catálogo entero.
 */
class ReportesRestantesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $admin;
    private User $colaborador;
    private Employee $expediente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Reportes 8 QA', 'subdomain' => 'rep8qa',
            'plan' => 'enterprise', 'is_active' => true,
        ]);

        $puesto = JobRole::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Cajero', 'area' => 'Piso', 'esAperturador' => true,
        ]);

        $this->admin = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Jefa', 'email' => 'jefa@rep8qa.test',
            'password' => bcrypt('x'), 'role' => 'admin',
        ]);

        $this->colaborador = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Pedro', 'email' => 'pedro@rep8qa.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);

        $this->expediente = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'name' => 'Pedro', 'job_role_id' => $puesto->id, 'is_active_employee' => true,
            'shiftStart' => '09:00', 'shiftEnd' => '18:00', 'mealMinutes' => 60,
            'hire_date' => now()->subDays(10)->toDateString(), 'salary' => 3000,
        ]);
    }

    private function csv(string $reporte, array $params = []): string
    {
        $query = $params ? '?' . http_build_query($params) : '';
        $r = $this->actingAs($this->admin)->get("/api/v1/admin/reports/{$reporte}.csv{$query}");
        $r->assertOk();

        return $r->streamedContent();
    }

    /**
     * EL CANDADO: todo reporte del catálogo tiene descarga real, y toda descarga está en el
     * catálogo. Sin esto, agregar un reporte al asistente y olvidar la ruta pasa inadvertido
     * hasta que un cliente pulsa el botón.
     */
    public function test_todo_reporte_del_catalogo_se_puede_descargar(): void
    {
        foreach (CatalogoDeReportes::ids() as $id) {
            $this->actingAs($this->admin)
                ->get("/api/v1/admin/reports/{$id}.csv")
                ->assertOk("el reporte '{$id}' está en el catálogo pero su descarga no responde");
        }

        $this->assertSame(
            ['asistencia', 'retardos', 'horas', 'rutinas', 'tareas', 'justificantes',
             'aperturas', 'comedor', 'academia', 'expedientes', 'reclutamiento', 'monedero',
             'rotacion', 'nomina_historica', 'costo_por_puesto'],
            CatalogoDeReportes::ids(),
            'si cambias el catálogo, revisa el prompt del asistente y la pantalla'
        );
    }

    /**
     * Los mismos 15, en documento. Un reporte nuevo hereda su PDF sin diseñarle nada, así que
     * lo que puede romperse es justo lo contrario: que uno de los 15 reviente al renderizarse
     * (una fila con un valor que la plantilla no espera) y nadie se entere hasta que el dueño
     * lo intenta. Por eso se recorre el catálogo entero, igual que con el CSV.
     */
    public function test_todo_reporte_del_catalogo_tambien_sale_en_pdf(): void
    {
        foreach (CatalogoDeReportes::ids() as $id) {
            $res = $this->actingAs($this->admin)->get("/api/v1/admin/reports/{$id}.csv?formato=pdf");

            $res->assertOk("el reporte '{$id}' no se pudo generar en PDF");
            $this->assertStringContainsString('pdf', strtolower($res->headers->get('content-type') ?? ''));
            $this->assertStringContainsString(".pdf", $res->headers->get('content-disposition') ?? '');
            // Un PDF de verdad, no una página de error con extensión bonita.
            $this->assertStringStartsWith('%PDF', $res->getContent(), "'{$id}' no devolvió un PDF válido");

            // Las fuentes base de dompdf son WinAnsi: un símbolo fuera de ese juego (una flecha,
            // un ⚠) se imprime como "?" suelto y nadie lo nota hasta que el documento ya se
            // entregó. Un "?" pegado a una palabra ("¿A tiempo?") es legítimo; uno solo, no.
            $this->assertDoesNotMatchRegularExpression(
                '/\s\?\s/',
                $this->textoDelPdf($res->getContent()),
                "'{$id}' imprime un símbolo que la fuente del PDF no tiene: agrégalo a sinSimbolosRaros()"
            );
        }
    }

    /**
     * El encabezado del documento: empresa, periodo y quién lo generó.
     *
     * Es lo que separa un PDF de una hoja impresa cualquiera — sin eso no sirve como evidencia
     * ni se puede archivar. Y es lo que se rompió en la primera versión: el periodo se sacaba
     * leyendo la primera nota del reporte, así que Rotación (que abre con "Altas contadas
     * entre…") salía sin fecha. Sólo se ve leyendo el PDF por dentro, no mirando el 200.
     */
    public function test_el_pdf_lleva_empresa_periodo_y_quien_lo_genero(): void
    {
        // Rotación es justo el que no anuncia su periodo con la frase de siempre.
        $res = $this->actingAs($this->admin)->get('/api/v1/admin/reports/rotacion.csv?formato=pdf');
        $texto = $this->textoDelPdf($res->getContent());

        $this->assertStringContainsString('Rotación de Personal', $texto);
        $this->assertStringContainsString('Reportes 8 QA', $texto, 'el documento no dice de qué empresa es');
        $this->assertStringContainsString('Periodo del ' . now()->subDays(89)->toDateString(), $texto, 'el documento no dice qué periodo cubre');
        $this->assertStringContainsString('Jefa', $texto, 'el documento no dice quién lo generó');
        // El pie decía "pág. 1 de 0": dompdf no conoce el total al pintar un elemento fijo.
        $this->assertStringNotContainsString('de 0', $texto, 'el pie está imprimiendo un total de páginas falso');
    }

    /** El texto de un PDF de dompdf: los flujos van comprimidos y el texto en operadores TJ. */
    private function textoDelPdf(string $pdf): string
    {
        $texto = '';
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $flujos);
        foreach ($flujos[1] as $flujo) {
            $crudo = @gzuncompress($flujo);
            if ($crudo === false) {
                continue;
            }
            preg_match_all('/\[\((.*?)\)\]\s*TJ/s', $crudo, $piezas);
            $texto .= implode(' ', $piezas[1]) . ' ';
        }

        // El PDF guarda el texto en WinAnsi, así que "Rotación" vuelve con la ó en un byte.
        return mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }

    /**
     * `formato=pdf` NO puede ser una puerta de atrás: es la MISMA ruta, así que estructuralmente
     * no lo es, pero un candado de dinero se prueba, no se razona.
     */
    public function test_el_pdf_respeta_el_candado_de_nomina(): void
    {
        $supervisor = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sup PDF', 'email' => 'suppdf@rep8qa.test',
            'password' => bcrypt('x'), 'role' => 'supervisor',
        ]);

        foreach (['nomina_historica', 'costo_por_puesto'] as $id) {
            $this->actingAs($supervisor)
                ->get("/api/v1/admin/reports/{$id}.csv?formato=pdf")
                ->assertForbidden("'{$id}' en PDF se le entregó a un supervisor sin la capacidad de nómina");
        }
    }

    /**
     * El candado del dinero: la nómina histórica NO puede caer en el grupo de reportes
     * operativos, que un supervisor puede bajar. Es la misma regla que la pantalla de nómina.
     */
    public function test_la_nomina_historica_exige_la_capacidad_de_nomina(): void
    {
        $supervisor = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Supervisor', 'email' => 'sup@rep8qa.test',
            'password' => bcrypt('x'), 'role' => 'supervisor',
        ]);

        // El supervisor baja los operativos…
        foreach (['rotacion', 'retardos', 'monedero'] as $id) {
            $this->actingAs($supervisor)->get("/api/v1/admin/reports/{$id}.csv")->assertOk();
        }
        // …pero el dinero no.
        $this->actingAs($supervisor)->getJson('/api/v1/admin/reports/nomina_historica.csv')->assertStatus(403);
        $this->actingAs($this->colaborador)->getJson('/api/v1/admin/reports/nomina_historica.csv')->assertStatus(403);
        $this->actingAs($this->admin)->get('/api/v1/admin/reports/nomina_historica.csv')->assertOk();

        $this->assertTrue(CatalogoDeReportes::esDeNomina('nomina_historica'));
        $this->assertNotContains('nomina_historica', CatalogoDeReportes::idsOperativos());

        // Y la pantalla tampoco le ofrece la tarjeta: prometer un botón que devuelve 403 es
        // el mismo defecto de "la pantalla dice lo que el backend no respalda".
        $delSupervisor = $this->actingAs($supervisor)->getJson('/api/v1/admin/reports/asistente/estado')
            ->assertOk()->json('catalogo');
        $this->assertNotContains('nomina_historica', array_column($delSupervisor, 'id'));

        $delAdmin = $this->actingAs($this->admin)->getJson('/api/v1/admin/reports/asistente/estado')
            ->assertOk()->json('catalogo');
        $this->assertContains('nomina_historica', array_column($delAdmin, 'id'));
    }

    /** La histórica lee lo GUARDADO y no recalcula: es lo que se firmó y se timbró. */
    public function test_la_nomina_historica_lee_el_recibo_guardado(): void
    {
        $inicio = now()->subDays(14)->startOfWeek()->toDateString();
        $fin = now()->subDays(14)->startOfWeek()->addDays(6)->toDateString();

        DB::table('weekly_payrolls')->insert([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->expediente->id,
            'start_date' => $inicio, 'end_date' => $fin,
            'base_salary_paid' => 3000, 'lates_count' => 2, 'absences_count' => 1,
            'deductions' => 450.50, 'net_pay' => 2549.50,
            'status' => 'approved_by_employee', 'employee_approved_at' => now()->subDays(10),
            'cfdi_uuid' => 'ABC-123-UUID', 'timbrada_at' => now()->subDays(9),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $csv = $this->csv('nomina_historica', ['from' => $inicio, 'to' => now()->toDateString()]);
        $renglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Pedro'));
        $campos = str_getcsv(trim($renglon));

        $this->assertSame('2549.50', $campos[8], 'el neto es el GUARDADO, no uno recalculado');
        $this->assertSame('450.50', $campos[7], 'deducciones guardadas');
        // El timbrado NO cambia el status en la base: se detecta por `timbrada_at` y se
        // añade al estado, porque un contador necesita verlo de un vistazo.
        $this->assertSame('Firmado por el colaborador · timbrado', $campos[9]);
        $this->assertSame('ABC-123-UUID', $campos[13], 'el folio fiscal viaja en el reporte');
        $this->assertStringContainsString('TOTAL FIRMADO DEL PERIODO', $csv);
        $this->assertStringContainsString('NO incluye ISR, IMSS', $csv, 'no puede parecer un neto fiscal');
        $this->assertStringContainsString('NO vuelve a calcular', $csv, 'el CSV declara que no recalcula');
    }

    /**
     * Un recibo en BORRADOR se recalcula solo cada noche: no es dinero comprometido y no
     * puede sumarse junto a lo firmado (sería otra vez "dos cifras para el mismo dato").
     */
    public function test_los_borradores_se_totalizan_aparte_de_lo_firmado(): void
    {
        $ini = now()->subDays(7)->startOfWeek();
        DB::table('weekly_payrolls')->insert([
            ['tenant_id' => $this->tenant->id, 'employee_id' => $this->expediente->id,
             'start_date' => $ini->copy()->subDays(7)->toDateString(), 'end_date' => $ini->copy()->subDay()->toDateString(),
             'base_salary_paid' => 3000, 'deductions' => 0, 'net_pay' => 1000,
             'status' => 'approved_by_employee', 'employee_approved_at' => now(),
             'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->tenant->id, 'employee_id' => $this->expediente->id,
             'start_date' => $ini->toDateString(), 'end_date' => $ini->copy()->addDays(6)->toDateString(),
             'base_salary_paid' => 3000, 'deductions' => 0, 'net_pay' => 999,
             'status' => 'draft', 'employee_approved_at' => null,
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $csv = $this->csv('nomina_historica', ['from' => $ini->copy()->subDays(14)->toDateString(), 'to' => now()->toDateString()]);

        $this->assertStringContainsString('BORRADORES (NO son dinero comprometido)', $csv);
        $this->assertStringContainsString('Borrador (por firmar)', $csv);
        // El total firmado no incluye el borrador de 999.
        $totalFirmado = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'TOTAL FIRMADO'));
        $this->assertStringContainsString('1000.00', $totalFirmado);
        $this->assertStringNotContainsString('1999', $totalFirmado);
    }

    /**
     * El desglose se guarda por los DOS caminos que escriben un recibo. Si uno lo guardara y
     * el otro no, el mismo periodo tendría cifras distintas según quién lo escribió.
     */
    public function test_el_desglose_se_guarda_al_calcular_y_al_firmar(): void
    {
        DB::table('lft_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id],
            ['late_tolerance_minutes' => 10, 'lates_per_absence' => 3, 'created_at' => now(), 'updated_at' => now()]
        );

        $payroll = app(\App\Services\ClockService::class)->calculatePayrollForEmployee(
            $this->expediente, now()->subDays(7)->toDateString(), now()->subDay()->toDateString()
        );
        $guardable = \App\Support\DesgloseDeNomina::paraGuardar($payroll, $this->expediente);

        // Las llaves del desglose existen y salen del motor, no de un cálculo paralelo.
        foreach (['gross_pay', 'holiday_bonus_pay', 'punctuality_bonus', 'opening_bonus',
                  'deduction_absences', 'deduction_rest_day', 'deduction_lates', 'daily_salary'] as $llave) {
            $this->assertArrayHasKey($llave, $guardable, "falta el concepto {$llave}");
        }
        $this->assertSame($payroll['salary']['gross'], $guardable['gross_pay']);
        $this->assertSame($payroll['deductions_breakdown']['absences'], $guardable['deduction_absences']);
        $this->assertSame('Cajero', $guardable['job_role_title_at_time'], 'el puesto queda congelado en el recibo');

        // Y las partes SUMAN el total que ya se guardaba (no es un desglose inventado).
        $this->assertEqualsWithDelta(
            $guardable['deductions'],
            $guardable['deduction_absences'] + $guardable['deduction_rest_day'] + $guardable['deduction_lates'],
            0.01,
            'las deducciones por concepto deben sumar el total del recibo'
        );

        // El colaborador firma: el mismo desglose queda guardado por ese camino.
        $this->actingAs($this->colaborador)->postJson('/api/v1/me/payroll/approve', [
            'start_date' => now()->subDays(7)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
        ]);
        $recibo = DB::table('weekly_payrolls')->where('employee_id', $this->expediente->id)->first();
        if ($recibo) {
            $this->assertNotNull($recibo->gross_pay, 'al firmar también se guarda el desglose');
            $this->assertSame('Cajero', $recibo->job_role_title_at_time);
        }
    }

    /** Costo por puesto: cuadra con el neto y separa los recibos sin desglose. */
    public function test_costo_por_puesto_cuadra_y_declara_los_recibos_viejos(): void
    {
        $ini = now()->subDays(20)->startOfWeek();

        DB::table('weekly_payrolls')->insert([
            // Con desglose (nuevo): entra en las sumas por concepto.
            ['tenant_id' => $this->tenant->id, 'employee_id' => $this->expediente->id,
             'start_date' => $ini->toDateString(), 'end_date' => $ini->copy()->addDays(6)->toDateString(),
             'base_salary_paid' => 3000, 'deductions' => 300, 'net_pay' => 2800,
             'gross_pay' => 3000, 'deduction_absences' => 200, 'deduction_lates' => 100,
             'deduction_rest_day' => 0, 'punctuality_bonus' => 100, 'opening_bonus' => 0,
             'holiday_bonus_pay' => 0, 'daily_salary' => 100,
             'job_role_title_at_time' => 'Cajero', 'job_role_area_at_time' => 'Piso',
             'status' => 'approved_by_employee', 'employee_approved_at' => now(),
             'created_at' => now(), 'updated_at' => now()],
            // Sin desglose (anterior al 2026-08-16): NO se reparte a ojo, se declara aparte.
            // Las mismas columnas que la fila de arriba, en null (el insert múltiple lo exige).
            ['tenant_id' => $this->tenant->id, 'employee_id' => $this->expediente->id,
             'start_date' => $ini->copy()->addDays(7)->toDateString(), 'end_date' => $ini->copy()->addDays(13)->toDateString(),
             'base_salary_paid' => 3000, 'deductions' => 500, 'net_pay' => 2500,
             'gross_pay' => null, 'deduction_absences' => null, 'deduction_lates' => null,
             'deduction_rest_day' => null, 'punctuality_bonus' => null, 'opening_bonus' => null,
             'holiday_bonus_pay' => null, 'daily_salary' => null,
             'job_role_title_at_time' => null, 'job_role_area_at_time' => null,
             'status' => 'approved_by_employee', 'employee_approved_at' => now(),
             'created_at' => now(), 'updated_at' => now()],
        ]);

        $csv = $this->csv('costo_por_puesto', ['from' => $ini->toDateString(), 'to' => now()->toDateString()]);

        $renglonPuesto = collect(explode("\n", $csv))->first(fn ($l) => str_starts_with($l, 'Puesto,'));
        $campos = str_getcsv(trim($renglonPuesto));

        $this->assertSame('Cajero', $campos[1]);
        $this->assertSame('2800.00', $campos[11], 'sólo el recibo CON desglose entra en el neto agrupado');
        $this->assertSame('200.00', $campos[5], 'descuento por faltas');
        $this->assertSame('100.00', $campos[6], 'descuento por retardos');
        $this->assertSame('300.00', $campos[8], 'total de deducciones = la suma de los conceptos');

        // LA CUENTA CUADRA: bruto − deducciones + bonos de cumplimiento = neto. Es lo primero
        // que va a verificar un contador, y la prima de festivo NO se suma dos veces (ya
        // viene dentro del bruto: así lo calcula el motor).
        $bruto = (float) $campos[4];
        $deducciones = (float) $campos[8];
        $bonos = (float) $campos[9];
        $neto = (float) $campos[11];
        $this->assertEqualsWithDelta($neto, $bruto - $deducciones + $bonos, 0.01,
            'sueldo − deducciones + bonos debe dar el neto');

        // Agrupa también por área, con el área congelada del recibo.
        $this->assertStringContainsString('Área,Piso', $csv);

        // Y DECLARA el recibo viejo en vez de repartirlo a ojo.
        $this->assertStringContainsString('1 recibo(s) de este periodo son anteriores', $csv);
        $this->assertStringContainsString('2,500.00', $csv, 'dice cuánto quedó fuera');
        $this->assertStringContainsString('no incluye ISR ni IMSS', $csv);
    }

    /** El costo por puesto también es dinero: mismo candado que la nómina histórica. */
    public function test_el_costo_por_puesto_exige_la_capacidad_de_nomina(): void
    {
        $supervisor = User::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sup2', 'email' => 'sup2@rep8qa.test',
            'password' => bcrypt('x'), 'role' => 'supervisor',
        ]);

        $this->actingAs($supervisor)->getJson('/api/v1/admin/reports/costo_por_puesto.csv')->assertStatus(403);
        $this->actingAs($this->admin)->get('/api/v1/admin/reports/costo_por_puesto.csv')->assertOk();
        $this->assertTrue(CatalogoDeReportes::esDeNomina('costo_por_puesto'));
    }

    /** Rotación: cuenta lo que sabe y DECLARA lo que no (las bajas sin fecha). */
    public function test_rotacion_distingue_bajas_con_y_sin_fecha(): void
    {
        // Baja registrada con el mecanismo nuevo (tiene fecha y motivo).
        $conFecha = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Se fue con fecha',
            'is_active_employee' => false, 'hire_date' => now()->subMonths(8)->toDateString(),
            'termination_date' => now()->subDays(20)->toDateString(),
            'termination_reason' => 'Renuncia voluntaria',
        ]);
        // Baja vieja: inactiva sin fecha (así estaban TODAS antes del 2026-08-16).
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Se fue sin fecha',
            'is_active_employee' => false, 'hire_date' => now()->subYear()->toDateString(),
        ]);

        $csv = $this->csv('rotacion');

        $conFechaRenglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Se fue con fecha'));
        $this->assertStringContainsString($conFecha->termination_date, $conFechaRenglon);
        $this->assertStringContainsString('Renuncia voluntaria', $conFechaRenglon);

        $sinFechaRenglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Se fue sin fecha'));
        $this->assertStringContainsString('Sin fecha', $sinFechaRenglon, 'no se inventa una fecha de baja');

        // fputcsv entrecomilla las etiquetas con espacios: se compara como sale.
        $this->assertStringContainsString('"Bajas con fecha registrada",1', $csv);
        $this->assertStringContainsString('"Bajas sin fecha (anteriores al registro)",1', $csv);
        $this->assertStringContainsString('"Plantilla activa hoy",1', $csv, 'sólo Pedro sigue activo');
    }

    /** Dar de baja AHORA sí deja fecha — que es lo que hace medible la rotación futura. */
    public function test_dar_de_baja_registra_la_fecha(): void
    {
        $this->actingAs($this->admin)
            ->deleteJson("/api/v1/employees/{$this->expediente->id}", ['motivo' => 'Fin de contrato'])
            ->assertSuccessful();

        $this->expediente->refresh();
        $this->assertSame(now()->toDateString(), $this->expediente->termination_date);
        $this->assertSame('Fin de contrato', $this->expediente->termination_reason);
        $this->assertFalse((bool) $this->expediente->is_active_employee);
    }

    /** Todos respetan el tope de días y ninguno se le abre a un empleado raso. */
    public function test_todos_respetan_el_tope_y_el_rol(): void
    {
        $empleado = $this->colaborador;

        // `expedientes` es una FOTO de hoy, no un periodo: no lee el rango (y lo dice en el CSV).
        $conPeriodo = array_values(array_diff(CatalogoDeReportes::ids(), ['expedientes']));

        foreach ($conPeriodo as $id) {
            $this->actingAs($this->admin)
                ->getJson("/api/v1/admin/reports/{$id}.csv?from=1990-01-01&to=2026-08-15")
                ->assertStatus(422, "el reporte '{$id}' aceptó un rango de 36 años");

            $this->actingAs($empleado)
                ->getJson("/api/v1/admin/reports/{$id}.csv")
                ->assertStatus(403, "el reporte '{$id}' se le abrió a un empleado raso");
        }
    }

    public function test_justificantes_muestra_lo_aprobado_y_quien_lo_resolvio(): void
    {
        $hoy = now()->toDateString();

        DB::table('late_justifications')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id, 'date' => $hoy,
            'reason' => 'Se me ponchó una llanta en el periférico', 'requested_late_minutes' => 25,
            'status' => 'approved', 'resolved_by' => $this->admin->id, 'resolved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('overtime_authorizations')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id, 'date' => $hoy,
            'kind' => 'holiday', 'authorized_by' => $this->admin->id, 'method' => 'pin',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $csv = $this->csv('justificantes', ['from' => $hoy, 'to' => $hoy]);

        $this->assertStringContainsString('Justificante de retardo', $csv);
        $this->assertStringContainsString('Aprobado', $csv);
        $this->assertStringContainsString('ponchó una llanta', $csv, 'el motivo es la evidencia');
        $this->assertStringContainsString('Jefa', $csv, 'debe decir quién lo resolvió');
        $this->assertStringContainsString('Autorización de horas extra', $csv);
        $this->assertStringContainsString('Día festivo', $csv);
    }

    /** "A tiempo" tiene que ser la MISMA regla que paga el bono de apertura. */
    public function test_aperturas_usa_la_misma_regla_que_el_bono(): void
    {
        $hoy = now()->toDateString();
        DB::table('lft_settings')->updateOrInsert(
            ['tenant_id' => $this->tenant->id],
            ['late_tolerance_minutes' => 10, 'opening_bonus_per_open' => 50, 'created_at' => now(), 'updated_at' => now()]
        );

        // Abrió 5 minutos tarde: DENTRO de la tolerancia de 10 → cuenta como a tiempo.
        DB::table('store_daily_opening_statuses')->insert([
            'tenant_id' => $this->tenant->id, 'store_id' => 1, 'date' => $hoy,
            'scheduled_opening_time' => '08:00:00', 'pre_opening_window_start' => '07:30:00',
            'report_deadline' => '08:15:00', 'status' => 'opened',
            'opened_by_employee_id' => $this->colaborador->id,
            'opened_at' => \Carbon\Carbon::parse("{$hoy} 08:05:00", \App\Helpers\TenantTimezone::for($this->tenant->id))->utc(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $csv = $this->csv('aperturas', ['from' => $hoy, 'to' => $hoy]);
        $this->assertStringContainsString('A tiempo', $csv);
        $this->assertStringContainsString('Pedro', $csv);

        // Y el motor del bono dice lo mismo: una apertura a tiempo.
        $this->assertSame(1, \App\Services\ClockService::aperturasATiempo(
            $this->tenant->id, $this->colaborador->id, $hoy, $hoy
        ), 'el reporte y el bono deben contar igual');
    }

    public function test_comedor_calcula_el_exceso_contra_los_minutos_del_expediente(): void
    {
        $hoy = now()->toDateString();
        foreach ([['meal_start', '14:00:00'], ['meal_end', '15:20:00']] as [$tipo, $hora]) {
            DB::table('time_entries')->insert([
                'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
                'date' => $hoy, 'type' => $tipo, 'time' => $hora, 'is_late' => false, 'late_minutes' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Ley Silla: SIN esta fila el reporte pasaba en pruebas y tronaba en producción con un
        // 500 — la consulta a `silla_requests` nunca se ejercía (columna real: `employee_id`,
        // que apunta a users; y "otorgada" incluye active/finished, no sólo approved).
        DB::table('silla_requests')->insert([
            ['tenant_id' => $this->tenant->id, 'store_id' => 1, 'employee_id' => $this->colaborador->id,
             'requested_at' => now(), 'status' => 'finished', 'created_at' => now(), 'updated_at' => now()],
            ['tenant_id' => $this->tenant->id, 'store_id' => 1, 'employee_id' => $this->colaborador->id,
             'requested_at' => now(), 'status' => 'rejected', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $csv = $this->csv('comedor', ['from' => $hoy, 'to' => $hoy]);
        $renglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Pedro'));
        $campos = str_getcsv(trim($renglon));

        $this->assertSame('80', $campos[5], '80 minutos de comida');
        $this->assertSame('60', $campos[6], 'los permitidos salen del expediente');
        $this->assertSame('20', $campos[7], 'exceso de 20');
        $this->assertSame('2', $campos[9], 'dos solicitudes de Ley Silla');
        $this->assertSame('1', $campos[10], 'sólo una se otorgó');
    }

    public function test_academia_marca_la_induccion_vencida_con_el_plazo_real(): void
    {
        // Ingresó hace 10 días y no ha completado inducción: el plazo son 3.
        $csv = $this->csv('academia');
        $renglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Pedro'));

        $this->assertStringContainsString('VENCIDA', $renglon);
        $this->assertStringContainsString((string) \App\Support\PlazoInduccion::DIAS, $csv,
            'el CSV debe decir cuál es el plazo');

        // La jefa (admin) NO aparece: tampoco recibe el aviso de inducción.
        $this->assertStringNotContainsString('Jefa', $csv);
    }

    public function test_expedientes_dice_que_falta_y_cuenta_los_validados(): void
    {
        DB::table('employee_documents')->insert([
            ['tenant_id' => $this->tenant->id, 'employee_id' => $this->expediente->id,
             'doc_type' => 'ine', 'original_name' => 'ine.pdf', 'path' => 'x/ine.pdf',
             'mime' => 'application/pdf', 'size_bytes' => 100, 'status' => 'validado',
             'created_at' => now(), 'updated_at' => now(),
             'rejection_reason' => null, 'validated_at' => now(), 'validated_by' => $this->admin->id,
             'uploaded_by' => $this->admin->id, 'deleted_at' => null],
            ['tenant_id' => $this->tenant->id, 'employee_id' => $this->expediente->id,
             'doc_type' => 'curp', 'original_name' => 'curp.pdf', 'path' => 'x/curp.pdf',
             'mime' => 'application/pdf', 'size_bytes' => 100, 'status' => 'rechazado',
             'rejection_reason' => 'Ilegible', 'created_at' => now(), 'updated_at' => now(),
             // El insert múltiple exige las MISMAS columnas en ambas filas.
             'validated_at' => null, 'validated_by' => null, 'uploaded_by' => null, 'deleted_at' => null],
        ]);

        $csv = $this->csv('expedientes');
        $renglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Pedro'));

        $this->assertStringContainsString('1/6', $renglon, 'un validado de seis');
        $this->assertStringContainsString('Ilegible', $renglon, 'el motivo del rechazo se ve');
        $this->assertStringContainsString('CURP', $renglon, 'el rechazado cuenta como faltante');
    }

    public function test_reclutamiento_agrupa_por_vacante_y_admite_no_saber_los_tiempos(): void
    {
        $vacante = DB::table('vacancies')->insertGetId([
            'tenant_id' => $this->tenant->id, 'job_role_id' => $this->expediente->job_role_id,
            'title' => 'Cajero de fin de semana', 'description' => 'x', 'requirements' => '[]',
            'is_active' => true, 'is_hidden' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['Ana', 'hired'], ['Beto', 'rejected'], ['Caro', 'interview']] as [$nombre, $estado]) {
            DB::table('candidates')->insert([
                'tenant_id' => $this->tenant->id, 'name' => $nombre, 'email' => strtolower($nombre) . '@x.test',
                'status' => $estado, 'applied_vacancy_id' => $vacante,
                'created_at' => now()->subDays(2), 'updated_at' => now(),
            ]);
        }

        $csv = $this->csv('reclutamiento');

        $this->assertStringContainsString('5. Contratado', $csv);
        $this->assertStringContainsString('Contratados: 1', $csv);
        $this->assertStringContainsString('Rechazados: 1', $csv);
        $this->assertStringContainsString('En proceso: 1', $csv);
        // Honestidad: el sistema no guarda cuándo cambió de etapa.
        $this->assertStringContainsString('no puede decir cuánto tardó en cada una', $csv);
    }

    public function test_monedero_usa_el_saldo_autoritativo_y_lo_ganado_en_el_periodo(): void
    {
        $tarea = DB::table('tasks')->insertGetId([
            'tenant_id' => $this->tenant->id, 'title' => 'Barrer', 'estimated_mins' => 10,
            'priority' => 'normal', 'category' => 'operativo', 'target_type' => 'role', 'target_id' => 1,
            'points' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('task_assignments')->insert([
            'id' => 'mon_1', 'tenant_id' => $this->tenant->id, 'task_id' => $tarea,
            'user_id' => $this->colaborador->id, 'date' => now()->toDateString(), 'status' => 'completed',
            'coins_awarded' => 1.5, 'points_awarded' => 15, 'validated_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_wallets')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->colaborador->id,
            'balance_coins' => 42.5, 'total_earned_coins' => 90, 'xp_points' => 300, 'level' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $csv = $this->csv('monedero');
        $renglon = collect(explode("\n", $csv))->first(fn ($l) => str_contains($l, 'Pedro'));
        $campos = str_getcsv(trim($renglon));

        $this->assertSame('1.5', $campos[3], 'monedas ganadas en el periodo');
        $this->assertSame('15', $campos[4], 'puntos del periodo');
        $this->assertSame('1', $campos[5], 'validada por un mando');
        $this->assertSame('42.5', $campos[7], 'el saldo sale de user_wallets, no de sumar transacciones');
        $this->assertSame('3', $campos[9], 'nivel');
    }

    /** Ninguno se cuela a otra empresa. */
    public function test_ningun_reporte_filtra_datos_de_otra_empresa(): void
    {
        $otra = Tenant::create(['name' => 'Ajena', 'subdomain' => 'ajena8', 'plan' => 'pro', 'is_active' => true]);
        $suUsuario = User::create([
            'tenant_id' => $otra->id, 'name' => 'ESPÍA AJENA', 'email' => 'espia@ajena8.test',
            'password' => bcrypt('x'), 'role' => 'empleado',
        ]);
        Employee::create([
            'tenant_id' => $otra->id, 'user_id' => $suUsuario->id, 'name' => 'ESPÍA AJENA',
            'is_active_employee' => true, 'hire_date' => now()->subDays(30)->toDateString(),
        ]);
        DB::table('late_justifications')->insert([
            'tenant_id' => $otra->id, 'user_id' => $suUsuario->id, 'date' => now()->toDateString(),
            'reason' => 'motivo de otra empresa', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (CatalogoDeReportes::ids() as $id) {
            $this->assertStringNotContainsString('ESPÍA AJENA', $this->csv($id),
                "el reporte '{$id}' filtró datos de otra empresa");
        }
    }
}
