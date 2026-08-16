<?php

namespace App\Http\Controllers;

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
        $totales = [];
        foreach ($firmados->groupBy(fn ($r) => $r->start_date . ' → ' . $r->end_date) as $periodo => $lista) {
            $totales[] = [
                '', $periodo, 'TOTAL FIRMADO DEL PERIODO', $lista->count() . ' colaboradores',
                number_format($lista->sum('base_salary_paid'), 2, '.', ''),
                $lista->sum('lates_count'),
                $lista->sum('absences_count'),
                number_format($lista->sum('deductions'), 2, '.', ''),
                number_format($lista->sum('net_pay'), 2, '.', ''),
                $lista->whereNotNull('admin_approved_at')->count() . ' autorizados · '
                    . $lista->whereNotNull('timbrada_at')->count() . ' timbrados',
                '', '', '', '', '',
            ];
        }
        if ($borradores->isNotEmpty()) {
            $totales[] = [
                '', '', 'BORRADORES (NO son dinero comprometido)', $borradores->count() . ' recibos',
                '', '', '', '', number_format($borradores->sum('net_pay'), 2, '.', ''),
                'Se recalculan solos hasta que el colaborador firma', '', '', '', '', '',
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
            'IMPORTANTE PARA CONTABILIDAD: el "neto del recibo" es el sueldo del periodo menos las deducciones internas (faltas y retardos) más los bonos. NO incluye ISR, IMSS ni subsidio: este sistema no calcula retenciones fiscales.',
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
        ], array_merge($filas, [[]], $totales), $notas);
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

        $resumen = [
            [],
            ['RESUMEN'],
            ['Plantilla activa hoy', $activos],
            ["Altas entre {$desde} y {$hasta}", $altasEnPeriodo],
            ['Antigüedad promedio de la plantilla activa', $antiguedadPromedio],
            ['Bajas con fecha registrada', $bajasConFecha],
            ['Bajas sin fecha (anteriores al registro)', $bajasSinFecha],
        ];

        return $this->csv("rotacion_{$desde}_a_{$hasta}.csv", [
            'Colaborador', 'Puesto', 'Fecha de ingreso', 'Situación', 'Fecha de baja',
            'Motivo de la baja', 'Permanencia', '¿Archivado?',
        ], array_merge($filas, array_map(fn ($r) => array_pad($r, 8, ''), $resumen)), [
            "Altas contadas entre {$desde} y {$hasta}; la plantilla y la antigüedad son de HOY.",
            'Hasta el 2026-08-16 el sistema no registraba la fecha de baja: por eso hay bajas "sin fecha". De esa fecha en adelante, cada baja queda con su día y su motivo, y entonces sí se podrá calcular el índice de rotación del periodo.',
            'La permanencia de quien sigue activo se cuenta hasta hoy; la de quien se fue, hasta su fecha de baja (si la tiene).',
            'Las fechas de ingreso de altas muy viejas se rellenaron con la fecha en que se creó el registro: pueden no ser exactas.',
            'Un reingreso limpia la baja anterior: la persona cuenta como activa desde su recontratación.',
        ]);
    }

    /**
     * Los estados que el código ESCRIBE de verdad son tres: `draft` (el cálculo nocturno),
     * `approved_by_employee` (la firma) y `approved_by_admin` (la autorización). El timbrado
     * NO cambia el estado — se detecta por `timbrada_at`, así que se añade aquí.
     */
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
