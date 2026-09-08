<?php

namespace App\Http\Controllers;

use App\Support\ReferenciaFiscal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Nómina histórica y rotación de personal (2026-08-16).
 *
 * Estos dos van APARTE de los reportes operativos por dos razones distintas:
 *
 *  - NÓMINA HISTÓRICA trae dinero, así que vive detrás de `permission:manage_payroll` — el
 *    mismo candado que la pantalla de nómina. Un supervisor con reportes operativos NO puede
 *    bajarlo. Y NO RECALCULA NADA: lee los recibos guardados, que es lo que la decisión D1
 *    del dueño declaró autoritativo ("manda el neto FIRMADO"). Recalcular el pasado con la
 *    asistencia de hoy es exactamente el defecto de la tarjeta de $4,875.
 *
 *  - ROTACIÓN es de RRHH (sin dinero), pero arrastra un límite que hay que decir en voz alta:
 *    hasta el 2026-08-16 el sistema no registraba CUÁNDO se iba la gente. Desde esa fecha sí
 *    (`employees.termination_date`), pero las bajas anteriores no tienen fecha y el reporte lo
 *    declara en vez de inventarla.
 */
class ReportesNominaController extends Controller
{
    use ArmaReportesCsv;

    /**
     * NÓMINA HISTÓRICA: los recibos tal como se guardaron, periodo por periodo.
     * Es el respaldo de "cuánto se le pagó a quién" — para una aclaración, una auditoría o
     * una junta con el contador.
     */
    public function historica(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'nomina_historica');

        $recibos = DB::table('weekly_payrolls')
            ->join('employees', 'employees.id', '=', 'weekly_payrolls.employee_id')
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->leftJoin('users as autorizador', 'autorizador.id', '=', 'weekly_payrolls.admin_approved_by')
            ->where('weekly_payrolls.tenant_id', $tenantId)
            // Un recibo soft-borrado no cuenta como dinero pagado.
            ->whereNull('weekly_payrolls.deleted_at')
            // El periodo se toma por su INICIO: un recibo pertenece al periodo que cubre.
            ->whereBetween('weekly_payrolls.start_date', [$desde, $hasta])
            ->orderBy('weekly_payrolls.start_date')
            ->orderBy('employees.name')
            ->get([
                'weekly_payrolls.start_date', 'weekly_payrolls.end_date', 'employees.name',
                'job_roles.name as puesto', 'weekly_payrolls.base_salary_paid',
                'weekly_payrolls.lates_count', 'weekly_payrolls.absences_count',
                'weekly_payrolls.deductions', 'weekly_payrolls.net_pay',
                'weekly_payrolls.status', 'weekly_payrolls.employee_approved_at',
                'weekly_payrolls.admin_approved_at', 'autorizador.name as autorizo',
                'weekly_payrolls.cfdi_uuid', 'weekly_payrolls.timbrada_at',
            ]);

        // Un `draft` lo REESCRIBE el cálculo nocturno mientras siga siendo el último periodo
        // cerrado: no es dinero comprometido y no puede sumarse junto a lo firmado.
        $firmados = $recibos->filter(fn ($r) => $r->status !== 'draft');
        $borradores = $recibos->filter(fn ($r) => $r->status === 'draft');

        $filas = [];
        foreach ($recibos as $r) {
            $filas[] = [
                $r->start_date, $r->end_date,
                $r->name, $r->puesto ?: 'Sin puesto',
                number_format((float) $r->base_salary_paid, 2, '.', ''),
                (int) $r->lates_count,
                (int) $r->absences_count,
                number_format((float) $r->deductions, 2, '.', ''),
                number_format((float) $r->net_pay, 2, '.', ''),
                $this->estadoDelRecibo($r->status, $r->timbrada_at),
                $r->employee_approved_at ? Carbon::parse($r->employee_approved_at)->format('Y-m-d H:i') : '',
                $r->admin_approved_at ? Carbon::parse($r->admin_approved_at)->format('Y-m-d H:i') : '',
                $r->autorizo ?: '',
                $r->cfdi_uuid ?: '',
                $r->timbrada_at ? Carbon::parse($r->timbrada_at)->format('Y-m-d') : '',
            ];
        }

        // Totales por periodo, SEPARANDO lo comprometido de lo que aún es borrador.
        //
        // Con SUS columnas, no rellenados hasta las 15 de la tabla de arriba: así el Excel los
        // pone en su propia hoja y ordenar los recibos deja de revolverlos con los totales.
        $totales = [];
        foreach ($firmados->groupBy(fn ($r) => $r->start_date . ' → ' . $r->end_date) as $periodo => $lista) {
            $totales[] = [
                $periodo,
                'FIRMADO (dinero comprometido)',
                $lista->count(),
                number_format($lista->sum('base_salary_paid'), 2, '.', ''),
                $lista->sum('lates_count'),
                $lista->sum('absences_count'),
                number_format($lista->sum('deductions'), 2, '.', ''),
                number_format($lista->sum('net_pay'), 2, '.', ''),
                $lista->whereNotNull('admin_approved_at')->count() . ' autorizados · '
                    . $lista->whereNotNull('timbrada_at')->count() . ' timbrados',
            ];
        }
        if ($borradores->isNotEmpty()) {
            $totales[] = [
                'Todos los periodos',
                'BORRADOR (NO es dinero comprometido)',
                $borradores->count(),
                '', '', '', '',
                number_format($borradores->sum('net_pay'), 2, '.', ''),
                'Se recalculan solos hasta que el colaborador firma',
            ];
        }

        // Traslapes: si la empresa cambió de periodicidad, dos recibos FIRMADOS pueden cubrir
        // los mismos días y sumar dinero dos veces. Se detecta y se avisa; no se oculta.
        $traslapes = [];
        foreach ($firmados->groupBy('name') as $quien => $suyos) {
            $lista = $suyos->values();
            for ($i = 0; $i < $lista->count(); $i++) {
                for ($j = $i + 1; $j < $lista->count(); $j++) {
                    if ($lista[$i]->start_date <= $lista[$j]->end_date && $lista[$j]->start_date <= $lista[$i]->end_date) {
                        $traslapes[] = "{$quien}: {$lista[$i]->start_date}→{$lista[$i]->end_date} y {$lista[$j]->start_date}→{$lista[$j]->end_date}";
                    }
                }
            }
        }

        $notas = [
            "Periodo del {$desde} al {$hasta} (por la fecha de inicio de cada recibo).",
            'Estas cifras son las GUARDADAS en cada recibo: es lo que se firmó, se autorizó y se timbró. Este reporte NO vuelve a calcular nada con la asistencia de hoy — si un dato de asistencia se corrigió después, el recibo firmado no cambia (así debe ser).',
            'IMPORTANTE PARA CONTABILIDAD: el "neto del recibo" es el sueldo del periodo menos las deducciones internas (faltas y retardos) más los bonos. NO incluye ISR, IMSS ni subsidio. Para las cifras de referencia del contador (gravado/exento, SBC, ISR e IMSS estimados) usa el reporte "Pre-nomina para tu Contador".',
            'El "sueldo del periodo" es el sueldo capturado en el expediente, no un bruto con horas extra ni bonos desglosados: el sistema no guarda ese desglose.',
            'Un recibo en BORRADOR se vuelve a calcular solo cada noche mientras es el periodo más reciente: por eso se totaliza aparte y no se suma con lo firmado.',
            'Sólo aparecen los periodos que el sistema alcanzó a calcular: si el cálculo nocturno no corrió una semana, esa semana no existe aquí (no es que nadie haya trabajado).',
            'El sistema no registra el PAGO en sí: lo que consta es que la empresa autorizó y, en su caso, que se timbró el CFDI.',
            'Contiene datos salariales: sólo lo descarga quien tiene la capacidad de nómina.',
        ];
        if ($traslapes) {
            $notas[] = '⚠ ATENCIÓN: hay recibos firmados que cubren días repetidos (suele pasar al cambiar de semanal a quincenal). Revísalos antes de usar los totales: ' . implode(' | ', array_slice($traslapes, 0, 5));
        }

        return $this->csv("nomina_historica_{$desde}_a_{$hasta}.csv", [
            'Periodo inicia', 'Periodo termina', 'Colaborador', 'Puesto', 'Sueldo del periodo',
            'Retardos', 'Faltas', 'Deducciones', 'Neto del recibo (sin ISR/IMSS)', 'Estado del recibo',
            'Firmado por el colaborador', 'Autorizado el', 'Autorizó', 'Folio fiscal (UUID)', 'Timbrado el',
        ], $filas, $notas, [
            'titulo' => 'Totales por periodo',
            'encabezados' => [
                'Periodo', 'Situación', 'Recibos', 'Sueldo del periodo', 'Retardos', 'Faltas',
                'Deducciones', 'Neto (sin ISR/IMSS)', 'Avance',
            ],
            'filas' => $totales,
        ]);
    }

    /**
     * ROTACIÓN DE PERSONAL: altas, bajas, plantilla y antigüedad.
     *
     * Lo que este reporte NO puede decir (y lo dice): el índice de rotación clásico de las
     * bajas anteriores al 2026-08-16, porque hasta esa fecha dar de baja no registraba la
     * fecha. Se puede inventar un número o se puede decir la verdad; aquí se dice la verdad.
     */
    public function rotacion(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'rotacion');
        $hoy = Carbon::today();

        // withTrashed: los archivados son parte de la historia de rotación.
        $gente = DB::table('employees')
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->where('employees.tenant_id', $tenantId)
            ->get(['employees.name', 'job_roles.name as puesto', 'employees.hire_date',
                   'employees.termination_date', 'employees.termination_reason',
                   'employees.is_active_employee', 'employees.deleted_at', 'employees.created_at']);

        $filas = [];
        $altasEnPeriodo = 0;
        $bajasConFecha = 0;
        $bajasSinFecha = 0;
        $activos = 0;
        $antiguedades = [];

        foreach ($gente as $p) {
            // OJO: con `DB::table` los booleanos llegan como 0/1 (o '0'/'1' según el motor),
            // así que `!== false` NUNCA sería falso y todos los inactivos pasarían por activos.
            $activo = ($p->is_active_employee === null || (bool) $p->is_active_employee)
                && $p->deleted_at === null;
            $ingreso = $p->hire_date ? Carbon::parse($p->hire_date) : null;
            $salida = $p->termination_date ? Carbon::parse($p->termination_date) : null;

            if ($ingreso && $ingreso->between(Carbon::parse($desde), Carbon::parse($hasta))) {
                $altasEnPeriodo++;
            }

            if ($activo) {
                $activos++;
                if ($ingreso) {
                    $antiguedades[] = $ingreso->diffInDays($hoy);
                }
            } else {
                $salida ? $bajasConFecha++ : $bajasSinFecha++;
            }

            $permanencia = '';
            if ($ingreso) {
                $hastaCuando = $salida ?: ($activo ? $hoy : null);
                if ($hastaCuando) {
                    $permanencia = $this->enMeses($ingreso->diffInDays($hastaCuando));
                }
            }

            $filas[] = [
                $p->name,
                $p->puesto ?: 'Sin puesto',
                $p->hire_date ?: 'Sin fecha capturada',
                $activo ? 'Activo' : 'Baja',
                $salida ? $salida->toDateString() : ($activo ? '' : 'Sin fecha (baja anterior al registro de fechas)'),
                $p->termination_reason ?: '',
                $permanencia,
                $p->deleted_at ? 'Archivado' : '',
            ];
        }

        // Activos primero, y dentro de cada grupo el más reciente arriba.
        usort($filas, fn ($a, $b) => [$a[3], $b[2]] <=> [$b[3], $a[2]]);

        $antiguedadPromedio = $antiguedades ? $this->enMeses((int) round(array_sum($antiguedades) / count($antiguedades))) : '—';

        // Dos columnas de verdad, no siete vacías de relleno para que cupieran en la tabla.
        $resumen = [
            ['Plantilla activa hoy', $activos],
            ["Altas entre {$desde} y {$hasta}", $altasEnPeriodo],
            ['Antigüedad promedio de la plantilla activa', $antiguedadPromedio],
            ['Bajas con fecha registrada', $bajasConFecha],
            ['Bajas sin fecha (anteriores al registro)', $bajasSinFecha],
        ];

        return $this->csv("rotacion_{$desde}_a_{$hasta}.csv", [
            'Colaborador', 'Puesto', 'Fecha de ingreso', 'Situación', 'Fecha de baja',
            'Motivo de la baja', 'Permanencia', '¿Archivado?',
        ], $filas, [
            "Altas contadas entre {$desde} y {$hasta}; la plantilla y la antigüedad son de HOY.",
            'Hasta el 2026-08-16 el sistema no registraba la fecha de baja: por eso hay bajas "sin fecha". De esa fecha en adelante, cada baja queda con su día y su motivo, y entonces sí se podrá calcular el índice de rotación del periodo.',
            'La permanencia de quien sigue activo se cuenta hasta hoy; la de quien se fue, hasta su fecha de baja (si la tiene).',
            'Las fechas de ingreso de altas muy viejas se rellenaron con la fecha en que se creó el registro: pueden no ser exactas.',
            'Un reingreso limpia la baja anterior: la persona cuenta como activa desde su recontratación.',
        ], [
            'titulo' => 'Resumen de plantilla',
            'encabezados' => ['Indicador', 'Valor'],
            'filas' => $resumen,
        ]);
    }

    /**
     * Los estados que el código ESCRIBE de verdad son tres: `draft` (el cálculo nocturno),
     * `approved_by_employee` (la firma) y `approved_by_admin` (la autorización). El timbrado
     * NO cambia el estado — se detecta por `timbrada_at`, así que se añade aquí.
     */
    /**
     * COSTO DE NÓMINA POR PUESTO Y ÁREA.
     *
     * Sólo puede salir de recibos que traigan el desglose (guardado desde 2026-08-16). Los
     * recibos anteriores tienen el neto pero no sus partes: se cuentan aparte y se dicen, en
     * vez de repartir a ojo un total entre conceptos que no se guardaron.
     *
     * Agrupa por el puesto DEL MOMENTO del recibo, no por el de hoy: si alguien cambió de
     * puesto, su gasto pasado debe seguir contando donde de verdad ocurrió.
     */
    public function costoPorPuesto(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'costo_por_puesto');

        $recibos = DB::table('weekly_payrolls')
            ->join('employees', 'employees.id', '=', 'weekly_payrolls.employee_id')
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->where('weekly_payrolls.tenant_id', $tenantId)
            ->whereNull('weekly_payrolls.deleted_at')
            // Sólo lo COMPROMETIDO: un borrador se recalcula cada noche y no es un costo.
            ->where('weekly_payrolls.status', '!=', 'draft')
            ->whereBetween('weekly_payrolls.start_date', [$desde, $hasta])
            ->get([
                'weekly_payrolls.net_pay', 'weekly_payrolls.deductions', 'weekly_payrolls.base_salary_paid',
                'weekly_payrolls.gross_pay', 'weekly_payrolls.holiday_bonus_pay',
                'weekly_payrolls.punctuality_bonus', 'weekly_payrolls.opening_bonus',
                'weekly_payrolls.deduction_absences', 'weekly_payrolls.deduction_rest_day',
                'weekly_payrolls.deduction_lates', 'weekly_payrolls.job_role_title_at_time',
                'weekly_payrolls.job_role_area_at_time', 'job_roles.name as puesto_hoy',
                'job_roles.area as area_hoy', 'employees.id as employee_id',
            ]);

        $conDesglose = $recibos->filter(fn ($r) => $r->gross_pay !== null);
        $sinDesglose = $recibos->filter(fn ($r) => $r->gross_pay === null);

        $filas = [];
        foreach ([['Puesto', 'puesto'], ['Área', 'area']] as [$etiqueta, $eje]) {
            $grupos = $conDesglose->groupBy(function ($r) use ($eje) {
                return $eje === 'puesto'
                    ? ($r->job_role_title_at_time ?: $r->puesto_hoy ?: 'Sin puesto')
                    : ($r->job_role_area_at_time ?: $r->area_hoy ?: 'Sin área');
            });

            foreach ($grupos as $nombre => $lista) {
                // OJO: la prima de día festivo YA viene sumada dentro del bruto (así la
                // calcula el motor), así que NO se suma otra vez aquí — se muestra aparte
                // como informativa. Los bonos que sí se agregan al neto son puntualidad y
                // apertura: bruto − deducciones + esos bonos = neto. Cuadra, y hay prueba.
                $bonosDeCumplimiento = $lista->sum('punctuality_bonus') + $lista->sum('opening_bonus');
                $filas[] = [
                    $etiqueta, $nombre,
                    $lista->pluck('employee_id')->unique()->count(),
                    $lista->count(),
                    number_format($lista->sum('gross_pay'), 2, '.', ''),
                    number_format($lista->sum('deduction_absences'), 2, '.', ''),
                    number_format($lista->sum('deduction_lates'), 2, '.', ''),
                    number_format($lista->sum('deduction_rest_day'), 2, '.', ''),
                    number_format($lista->sum('deductions'), 2, '.', ''),
                    number_format($bonosDeCumplimiento, 2, '.', ''),
                    number_format($lista->sum('holiday_bonus_pay'), 2, '.', ''),
                    number_format($lista->sum('net_pay'), 2, '.', ''),
                    $this->pct((int) round($lista->sum('deductions')), (int) max(1, round($lista->sum('gross_pay')))),
                ];
            }
        }

        // Puesto primero, y dentro de cada eje el más caro arriba.
        usort($filas, fn ($a, $b) => [$a[0], (float) $b[11]] <=> [$b[0], (float) $a[11]]);

        $notas = [
            "Periodo del {$desde} al {$hasta} (por la fecha de inicio de cada recibo).",
            'Sólo cuenta lo COMPROMETIDO: los recibos en borrador se recalculan cada noche y no son un costo todavía.',
            'Cada recibo cuenta en el puesto y el área que tenía CUANDO se generó: si alguien cambió de puesto después, su gasto pasado no se mueve de lugar.',
            'El neto no incluye ISR ni IMSS: aqui no se retiene nada. El costo patronal real (cuotas, prestaciones) es mayor que esta cifra. Las cifras de referencia para el contador estan en el reporte "Pre-nomina para tu Contador".',
            'La cuenta cuadra así: Sueldo del periodo − Total deducciones + Bonos de cumplimiento = Neto pagado. Las tres deducciones por concepto suman el total.',
            'La "prima de festivos" se muestra sólo como informativa: YA está incluida dentro del sueldo del periodo, así que NO hay que volver a sumarla.',
        ];
        if ($sinDesglose->isNotEmpty()) {
            $notas[] = 'NOTA: ' . $sinDesglose->count() . ' recibo(s) de este periodo son anteriores al 2026-08-16 y no guardaron el desglose por concepto, así que NO están en estas sumas. Su neto total fue $'
                . number_format($sinDesglose->sum('net_pay'), 2) . ' — para verlos usa el reporte de Nómina Histórica.';
        }

        return $this->csv("costo_por_puesto_{$desde}_a_{$hasta}.csv", [
            'Agrupado por', 'Nombre', 'Personas', 'Recibos', 'Sueldo del periodo',
            'Descuento por faltas', 'Descuento por retardos', 'Ajuste séptimo día',
            'Total deducciones', 'Bonos de cumplimiento', 'Prima de festivos (ya en el sueldo)',
            'Neto pagado', '% deducido',
        ], $filas, $notas);
    }

    /**
     * PRE-NÓMINA PARA TU CONTADOR: lo mismo que ya se pagó, traducido al idioma en que lo
     * necesita el contador de la empresa — percepciones separadas en gravado y exento, salario
     * base de cotización, e ISR e IMSS ESTIMADOS.
     *
     * La decisión del dueño (2026-09-06) es que la nómina ORIENTA, no timbra. Este reporte es
     * exactamente esa frontera: da la referencia para arrancar, y dice con todas sus letras
     * que no sustituye el cálculo del contador ni es un recibo fiscal. El recibo del
     * trabajador NO cambia: aquí no se recalcula ni se guarda nada.
     *
     * Igual que la Pre-nómina Histórica, LEE los recibos guardados (D1: "manda el neto
     * FIRMADO") y no recalcula nada. **Los borradores SÍ entran** (decisión de Adán,
     * 2026-09-08): el contador necesita ver el periodo en curso ANTES de la firma, o el
     * reporte llega tarde para lo único que sirve, que es preparar la nómina. Pero entran
     * MARCADOS como provisionales y **totalizados aparte**: un borrador lo reescribe el
     * cálculo nocturno, así que sumarlo junto a lo firmado daría una cifra que cambia sola.
     * Es el mismo trato que les da la Pre-nómina Histórica, para que las dos digan lo mismo.
     *
     * Los recibos anteriores al desglose (2026-08-16) no traen las partes por concepto, así
     * que no se les puede separar gravado de exento: se declaran aparte en vez de inventarles
     * un desglose.
     */
    public function paraElContador(Request $request)
    {
        $tenantId = (int) $request->user()->tenant_id;
        [$desde, $hasta] = $this->rango($request, 'prenomina_contador');

        $recibos = DB::table('weekly_payrolls')
            ->join('employees', 'employees.id', '=', 'weekly_payrolls.employee_id')
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->where('weekly_payrolls.tenant_id', $tenantId)
            ->whereNull('weekly_payrolls.deleted_at')
            ->whereBetween('weekly_payrolls.start_date', [$desde, $hasta])
            ->orderBy('weekly_payrolls.start_date')
            ->orderBy('employees.name')
            ->get([
                'weekly_payrolls.start_date', 'weekly_payrolls.end_date', 'employees.name',
                'employees.hire_date', 'employees.periodicidad_captura', 'job_roles.name as puesto',
                'weekly_payrolls.gross_pay', 'weekly_payrolls.holiday_bonus_pay',
                'weekly_payrolls.punctuality_bonus', 'weekly_payrolls.opening_bonus',
                'weekly_payrolls.deductions', 'weekly_payrolls.daily_salary',
                'weekly_payrolls.net_pay', 'weekly_payrolls.job_role_title_at_time',
                'weekly_payrolls.status', 'weekly_payrolls.timbrada_at',
            ]);

        $conDesglose = $recibos->filter(fn ($r) => $r->gross_pay !== null && $r->daily_salary !== null);
        $sinDesglose = $recibos->filter(fn ($r) => $r->gross_pay === null || $r->daily_salary === null);
        $antesDeLaVigencia = $conDesglose->filter(fn ($r) => $r->start_date < ReferenciaFiscal::VIGENTE_DESDE);

        $filas = [];
        $porPeriodo = [];
        foreach ($conDesglose as $r) {
            $dias = (int) (Carbon::parse($r->start_date)->diffInDays(Carbon::parse($r->end_date)) + 1);
            $diario = (float) $r->daily_salary;
            $minimo = ReferenciaFiscal::esSalarioMinimo($diario);
            $bonos = (float) $r->punctuality_bonus + (float) $r->opening_bonus;

            $percepciones = ReferenciaFiscal::clasificaPercepciones(
                (float) $r->gross_pay,
                (float) $r->holiday_bonus_pay,
                $bonos,
                (float) $r->deductions,
                $dias,
                $minimo
            );

            $anios = ReferenciaFiscal::antiguedadEnAnios($r->hire_date, $r->start_date);
            $cotizacion = ReferenciaFiscal::salarioBaseDeCotizacion($diario, $anios);
            $isr = ReferenciaFiscal::isrDelPeriodo($percepciones['gravado'], $dias);
            $imss = ReferenciaFiscal::cuotaObreraImss($cotizacion['sbc'], $dias, $minimo);

            // No se puede retener MÁS de lo que se paga. Un periodo con faltas puede dejar el
            // recibo en cero mientras el IMSS sigue calculándose sobre el SBC: sin este tope el
            // reporte imprimía un neto NEGATIVO, que es una cifra que nadie puede usar. Las
            // columnas de ISR e IMSS conservan el importe calculado; lo que se topa es el neto,
            // y el renglón dice que se topó.
            $pagado = (float) $r->net_pay;
            $retenciones = round($isr['retencion'] + $imss['total'], 2);
            $netoEstimado = round(max(0.0, $pagado - $retenciones), 2);

            $observaciones = [];
            if ($retenciones > $pagado) {
                $observaciones[] = 'Las retenciones de referencia ($' . number_format($retenciones, 2)
                    . ') superan lo pagado en el periodo ($' . number_format($pagado, 2)
                    . '): no se puede retener mas de lo que se paga, asi que el neto estimado se dejo en cero. Suele pasar cuando el periodo trae faltas; revisa tambien el ausentismo del art. 31 LSS, que este reporte NO aplica.';
            }
            if ($minimo) {
                $observaciones[] = 'Salario minimo: la cuota obrera la cubre el patron (LSS art. 36) y la prima de festivo va 100% exenta (LISR art. 93 fr. I).';
            }
            if ($cotizacion['topado']) {
                $observaciones[] = 'SBC topado a 25 UMA (LSS art. 28).';
            }
            if ($anios === null) {
                $observaciones[] = 'Sin fecha de ingreso en el expediente: se uso el factor del primer ano.';
            }
            // El defecto que este reporte AMPLIFICA: sin periodicidad declarada, el sueldo diario
            // sale del supuesto historico (base/6). Si el monto capturado era MENSUAL, el diario
            // —y con el, el SBC y las cuotas de este renglon— salen hasta cinco veces mas altos.
            // El motor de pago ya lo marca como pendiente de recaptura; aqui hay que decirlo
            // porque es la cifra que el contador se llevaria como buena.
            if (!$r->periodicidad_captura) {
                $observaciones[] = 'OJO: el sueldo de esta persona NO declara periodicidad en su expediente, asi que el diario salio del supuesto historico. Si el monto capturado era mensual, el SBC y las cuotas de este renglon estan hasta 5 veces por encima: recaptura el sueldo antes de usar esta cifra.';
            }
            if ($bonos > 0 && $cotizacion['sbc'] > 0 && ($bonos / max(1, $dias)) > ($cotizacion['sbc'] * 0.10)) {
                $observaciones[] = 'Los bonos rebasan el 10% del SBC: su excedente INTEGRA al SBC (LSS art. 27 fr. VII) y este reporte no lo integro.';
            }

            // Un borrador NO es dinero comprometido: se reescribe cada noche mientras siga
            // siendo el último periodo cerrado. Entra, pero diciendo lo que es en su renglón.
            $provisional = $r->status === 'draft';
            $situacion = $provisional
                ? 'PROVISIONAL (borrador: se recalcula cada noche)'
                : $this->estadoDelRecibo($r->status, $r->timbrada_at);

            $filas[] = [
                $r->start_date, $r->end_date, $dias,
                $r->name, $r->job_role_title_at_time ?: ($r->puesto ?: 'Sin puesto'),
                $situacion,
                number_format($percepciones['sueldo'], 2, '.', ''),
                number_format($percepciones['prima_festivo'], 2, '.', ''),
                number_format($percepciones['bonos'], 2, '.', ''),
                number_format($percepciones['total'], 2, '.', ''),
                number_format($percepciones['gravado'], 2, '.', ''),
                number_format($percepciones['exento'], 2, '.', ''),
                number_format($diario, 2, '.', ''),
                $anios === null ? '' : $anios,
                number_format($cotizacion['factor'], 4, '.', ''),
                number_format($cotizacion['sbc'], 2, '.', ''),
                number_format($isr['causado'], 2, '.', ''),
                number_format($isr['subsidio'], 2, '.', ''),
                number_format($isr['retencion'], 2, '.', ''),
                number_format($imss['total'], 2, '.', ''),
                number_format((float) $r->net_pay, 2, '.', ''),
                number_format($netoEstimado, 2, '.', ''),
                implode(' ', $observaciones),
            ];

            // Los totales se parten en dos: lo comprometido no puede sumarse con lo que aún
            // se recalcula solo, o el contador se lleva una cifra que mañana es otra.
            $clave = ($provisional ? 'P' : 'C') . '|' . $r->start_date . ' → ' . $r->end_date;
            $porPeriodo[$clave] ??= ['periodo' => $r->start_date . ' → ' . $r->end_date,
                                     'situacion' => $provisional ? 'PROVISIONAL (NO sumar con lo firmado)' : 'COMPROMETIDO (firmado o autorizado)',
                                     'recibos' => 0, 'total' => 0.0, 'gravado' => 0.0, 'exento' => 0.0,
                                     'isr' => 0.0, 'imss' => 0.0, 'neto' => 0.0, 'estimado' => 0.0];
            $porPeriodo[$clave]['recibos']++;
            $porPeriodo[$clave]['total'] += $percepciones['total'];
            $porPeriodo[$clave]['gravado'] += $percepciones['gravado'];
            $porPeriodo[$clave]['exento'] += $percepciones['exento'];
            $porPeriodo[$clave]['isr'] += $isr['retencion'];
            $porPeriodo[$clave]['imss'] += $imss['total'];
            $porPeriodo[$clave]['neto'] += (float) $r->net_pay;
            $porPeriodo[$clave]['estimado'] += $netoEstimado;
        }

        // Lo comprometido primero: es lo que cuenta como cifra, y lo provisional va detrás.
        ksort($porPeriodo);
        $totales = [];
        foreach ($porPeriodo as $t) {
            $totales[] = [
                $t['periodo'], $t['situacion'], $t['recibos'],
                number_format($t['total'], 2, '.', ''),
                number_format($t['gravado'], 2, '.', ''),
                number_format($t['exento'], 2, '.', ''),
                number_format($t['isr'], 2, '.', ''),
                number_format($t['imss'], 2, '.', ''),
                number_format($t['neto'], 2, '.', ''),
                number_format($t['estimado'], 2, '.', ''),
            ];
        }

        $notas = [
            "Periodo del {$desde} al {$hasta} (por la fecha de inicio de cada recibo).",
            'CIFRAS DE REFERENCIA: no sustituyen el calculo del contador, y este sistema NO TIMBRA. Aqui no hay CFDI, ni sello, ni recibo fiscal; el recibo que firma el colaborador no cambia por este reporte.',
            'Tablas del ejercicio ' . ReferenciaFiscal::VIGENCIA . ', vigentes desde el ' . ReferenciaFiscal::VIGENTE_DESDE
                . ': UMA diaria $' . number_format(ReferenciaFiscal::UMA_DIARIA, 2)
                . ' (INEGI, DOF 09-ene-2026); tarifa mensual del art. 96 LISR del Anexo 8 de la RMF 2026 (DOF 28-dic-2025); subsidio al empleo '
                . number_format(ReferenciaFiscal::SUBSIDIO_FACTOR_UMA * 100, 2) . '% de la UMA mensual con tope de ingreso de $'
                . number_format(ReferenciaFiscal::SUBSIDIO_TOPE_INGRESO_MENSUAL, 2) . ' al mes (DOF 31-dic-2025); salario minimo general $'
                . number_format(ReferenciaFiscal::SALARIO_MINIMO_GENERAL, 2) . ' (CONASAMI).',
            'Como cuadra cada renglon: Sueldo pagado + Prima de festivos + Bonos = Total percepciones = Gravado + Exento, y Total percepciones = Neto del recibo. Los descuentos por faltas, retardos y septimo YA vienen restados del sueldo pagado: no son deducciones fiscales, son menos dias pagados.',
            'ISR: la tarifa mensual llevada a los dias del periodo (art. 175 RLISR). El subsidio al empleo se acredita contra el ISR; si lo excede, el excedente NO se le entrega al trabajador.',
            'IMSS: solo la CUOTA OBRERA (lo que se le retiene al trabajador) sobre el SBC, por todos los dias del periodo. La cuota patronal y el costo total del patron NO estan aqui. Este reporte NO aplica el ausentismo del art. 31 LSS: si el periodo trae faltas, las cuotas reales pueden ser menores y las ajusta el contador.',
            '"Neto estimado con retenciones" nunca baja de cero: no se puede retener mas de lo que se paga. Cuando las retenciones calculadas superan el pago del periodo, el renglon lo dice en Observaciones con el importe completo.',
            'SBC: es la parte FIJA (sueldo diario por el factor de integracion segun antiguedad, topado a 25 UMA). Los premios de puntualidad y asistencia no integran mientras cada uno no rebase el 10% del SBC (LSS art. 27 fr. VII); cuando lo rebasan, el renglon lo dice en Observaciones y el excedente lo integra el contador.',
            'Lo que este reporte NO sabe: si la empresa esta en la Zona Libre de la Frontera Norte (ahi el salario minimo es $'
                . number_format(ReferenciaFiscal::SALARIO_MINIMO_FRONTERA, 2)
                . '), ni si hay percepciones fuera del sistema (aguinaldo, vacaciones, prima vacacional, horas extra pagadas aparte, finiquitos). Todo eso lo agrega el contador.',
            'LA COLUMNA "Situacion" ES LA IMPORTANTE. Un recibo PROVISIONAL es un borrador: el calculo nocturno lo vuelve a escribir mientras siga siendo el periodo mas reciente, asi que su cifra puede cambiar. Esta aqui para que puedas preparar la nomina del periodo en curso, no para cerrarla. Por eso los totales van separados: NO sumes lo provisional con lo comprometido.',
            'Contiene datos salariales: solo lo descarga quien tiene la capacidad de nomina.',
        ];
        if ($sinDesglose->isNotEmpty()) {
            $notas[] = 'NOTA: ' . $sinDesglose->count() . ' recibo(s) de este periodo son anteriores al desglose por concepto (2026-08-16) y NO se pueden separar en gravado y exento, asi que quedan fuera de este reporte. Su neto total fue $'
                . number_format($sinDesglose->sum('net_pay'), 2) . ' — para verlos usa la Pre-nomina Historica.';
        }
        if ($antesDeLaVigencia->isNotEmpty()) {
            $notas[] = 'ATENCION: ' . $antesDeLaVigencia->count() . ' recibo(s) empiezan antes del ' . ReferenciaFiscal::VIGENTE_DESDE
                . ', cuando regian la UMA anterior y otro porcentaje de subsidio. Sus cifras salieron con las tablas de '
                . ReferenciaFiscal::VIGENCIA . ' y el contador debe recalcularlas.';
        }

        return $this->csv("prenomina_contador_{$desde}_a_{$hasta}.csv", [
            'Periodo inicia', 'Periodo termina', 'Días', 'Colaborador', 'Puesto', 'Situación',
            'Sueldo pagado', 'Prima de festivos', 'Bonos', 'Total percepciones',
            'Gravado', 'Exento', 'Salario diario', 'Antigüedad (años)', 'Factor de integración',
            'SBC', 'ISR causado', 'Subsidio al empleo', 'ISR a retener (estimado)',
            'IMSS cuota obrera (estimada)', 'Neto del recibo', 'Neto estimado con retenciones',
            'Observaciones',
        ], $filas, $notas, [
            'titulo' => 'Totales por periodo',
            'encabezados' => [
                'Periodo', 'Situación', 'Recibos', 'Total percepciones', 'Gravado', 'Exento',
                'ISR a retener (estimado)', 'IMSS obrero (estimado)', 'Neto del recibo',
                'Neto estimado con retenciones',
            ],
            'filas' => $totales,
        ]);
    }

    private function estadoDelRecibo(?string $status, $timbradaAt): string
    {
        $base = match ($status) {
            'draft', 'pending_employee' => 'Borrador (por firmar)',
            'approved_by_employee' => 'Firmado por el colaborador',
            'approved_by_admin' => 'Autorizado por la empresa',
            default => (string) $status,
        };

        return $timbradaAt ? $base . ' · timbrado' : $base;
    }

    private function enMeses(int $dias): string
    {
        if ($dias < 31) {
            return $dias . ' días';
        }
        $meses = round($dias / 30.44, 1);

        return $meses >= 12
            ? round($meses / 12, 1) . ' años'
            : $meses . ' meses';
    }
}
