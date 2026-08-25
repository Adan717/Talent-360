<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Employee;
use App\Models\TimeEntry;
use Carbon\Carbon;
use App\Services\ClockService;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Barryvdh\DomPDF\Facade\Pdf;

class PayrollController extends Controller
{
    protected ClockService $clockService;
    protected \App\Services\PayrollWeekService $weekService;

    public function __construct(ClockService $clockService, \App\Services\PayrollWeekService $weekService)
    {
        $this->clockService = $clockService;
        $this->weekService = $weekService;
    }

    private function getPeriodDates(Request $request)
    {
        $tenantId = auth()->user()->tenant_id ?? 1;
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if (!$startDate || !$endDate) {
            // Predeterminado: el último periodo CERRADO del tenant según su periodicidad
            // (N3/#17). Es lo que el batch calcula, el trabajador firma y la empresa
            // autoriza — el periodo en curso no tiene nada autorizable.
            // "Ahora" en la zona del TENANT, no en UTC: con `Carbon::now()` el periodo de
            // nómina saltaba al siguiente a las 18:00 hora de México (medianoche UTC), así
            // que la misma pantalla mostraba un periodo por la tarde y otro por la noche —
            // y el Excel/PDF salían del periodo equivocado. Familia H10/H21.
            [$start, $end] = $this->weekService->lastClosedPeriodFor(
                $tenantId,
                Carbon::now(\App\Helpers\TenantTimezone::for($tenantId))
            );
            $startDate = $start->toDateString();
            $endDate = $end->toDateString();
        } else {
            // N6 (candado, ahora period-aware): un rango explícito debe SER un periodo real
            // del tenant — su semana configurable, una quincena natural o un mes, según su
            // periodicidad. Antes cualquier quincena descontaba 15 días de faltas contra un
            // bruto de 7; mejor rechazar y decirlo que calcular mal.
            [$ps, $pe] = $this->weekService->periodRangeFor($tenantId, Carbon::parse($startDate));
            if ($startDate !== $ps->toDateString() || $endDate !== $pe->toDateString()) {
                abort(422, 'El periodo pedido no corresponde a un periodo de nómina de esta empresa '
                    . "(su periodo que contiene {$startDate} va del {$ps->toDateString()} al {$pe->toDateString()}). "
                    . 'Los recibos se calculan por periodos completos según la periodicidad configurada.');
            }
        }

        return [$startDate, $endDate];
    }

    private function calculatePayrollData(Request $request)
    {
        // Obtener todos los empleados activos del tenant
        $tenantId = auth()->user()->tenant_id ?? 1;

        [$startDate, $endDate] = $this->getPeriodDates($request);

        // Activos MÁS quien ya tiene nómina de este periodo aunque hoy esté dado de baja.
        //
        // Con sólo `is_active_employee != false`, alguien que trabajó la semana, firmó su
        // recibo y salió de la empresa el lunes DESAPARECÍA de la tabla y de los totales —
        // pero "Autorizar Pago de Nómina" sí autorizaba su pago (autorizarPeriodoCompleto
        // recorre las WeeklyPayroll del periodo, no la plantilla de hoy). La pantalla no
        // mostraba lo que el botón autoriza, y quien ya trabajó tiene que cobrar.
        $conNominaDelPeriodo = \App\Models\WeeklyPayroll::where('tenant_id', $tenantId)
            ->where('start_date', $startDate)
            ->where('end_date', $endDate)
            ->pluck('employee_id');

        $employees = Employee::where('tenant_id', $tenantId)
            ->where(function ($q) use ($conNominaDelPeriodo) {
                $q->where('is_active_employee', '!=', false)
                    ->orWhereIn('id', $conNominaDelPeriodo);
            })
            ->get();

        // "Reportes de Prueba" (sección 13 del contrato): un admin/platform_admin puede
        // pedir explícitamente el cálculo de nómina usando datos de una sesión del
        // Simulador Matrix en vez de los reales, para validar que el módulo funciona bien
        // con datos simulados sin que eso jamás toque un número real.
        $simulationSessionId = null;
        if ($request->query('simulation_session_id')) {
            $role = auth()->user()->role ?? null;
            if (!in_array($role, ['admin', 'platform_admin'])) {
                abort(403, 'Solo un administrador puede ver reportes de prueba del simulador.');
            }
            $simulationSessionId = (int) $request->query('simulation_session_id');
        }

        $payrollList = [];

        foreach ($employees as $employee) {
            $payroll = $this->clockService->calculatePayrollForEmployee($employee, $startDate, $endDate, $simulationSessionId);
            $payrollRow = [
                'id' => $employee->id,
                'name' => $employee->name,
                'role' => $employee->role ?? 'Colaborador',
                // La pantalla de timbrado necesita el RFC/CURP REALES del expediente (antes
                // leía usuarios, donde no existen esas columnas, y pintaba siempre el genérico).
                'rfc' => $employee->rfc,
                'curp' => $employee->curp,
                'lates' => $payroll['incidents']['lates'],
                'absences' => $payroll['incidents']['total_absences'],
                // Importes redondeados a centavos: son dinero. Sin esto salían con la cola del
                // float (`2722.222222222221`), que el panel pintaba como "2,722.222" —tres
                // decimales en pesos— y que también viajaba al Excel y al PDF de prenómina.
                'base' => round((float) $payroll['salary']['base'], 2),
                // BRUTO del periodo (diario × días + prima de festivo) y bono de cumplimiento:
                // sin ellos, la pantalla derivaba su propio total (Σbase − Σpenalty) que NO
                // coincidía con la columna "Neto a Pagar" de la tabla de abajo — dos cifras
                // distintas para el mismo dinero, y la grande no era la que se paga.
                'gross' => round((float) $payroll['salary']['gross'], 2),
                'compliance_bonus' => round((float) $payroll['salary']['compliance_bonus'], 2),
                'penalty' => round((float) $payroll['deductions_breakdown']['total'], 2),
                'net' => round((float) $payroll['salary']['net'], 2),
                // Lo declara el MOTOR (2026-08-24, Regla 4). Antes esta pantalla volvía a deducirlo
                // mirando el expediente por su cuenta, porque el motor sustituía el salario
                // faltante por un default escondido de $2,400 y `salary.base` no delataba nada.
                // Ese default ya no existe: sin sueldo capturado el cálculo sale en CERO y viene
                // marcado. Una sola regla, en un solo lugar — la segunda fuente de verdad que
                // señaló el consejo queda cerrada.
                'salary_pending' => (bool) ($payroll['salary']['pending'] ?? false),
                'rest_day_proportion' => $payroll['incidents']['rest_day_proportion'],
                'approval_status' => $payroll['approval']['status'],
                'cfdi_uuid' => $payroll['approval']['cfdi_uuid'],
                'net_firmado' => $payroll['approval']['net_registrado']
            ];

            if ($request->query('detailed') === 'true') {
                $payrollRow['days_details'] = $payroll['days_details'];
                $payrollRow['incidents'] = $payroll['incidents'];
                $payrollRow['deductions_breakdown'] = $payroll['deductions_breakdown'];
            }

            $payrollList[] = $payrollRow;
        }

        return $payrollList;
    }

    public function getPayrollData(Request $request)
    {
        // {period, employees} en vez del arreglo pelón: las pantallas mostraban "Quincena
        // Actual" y periodos inventados porque el backend jamás les decía QUÉ periodo era.
        [$startDate, $endDate] = $this->getPeriodDates($request);

        return response()->json([
            'period' => ['start_date' => $startDate, 'end_date' => $endDate],
            'employees' => $this->calculatePayrollData($request),
        ]);
    }

    public function exportReport(Request $request)
    {
        $format = $request->query('format', 'xlsx');
        $payroll = $this->calculatePayrollData($request);
        [$startDate, $endDate] = $this->getPeriodDates($request);

        // Cada total es la SUMA de su columna. Antes el neto se derivaba aquí como
        // (Σbase − Σpenalty) igual que en la pantalla, así que el PDF de prenómina no
        // cuadraba con su propia columna "Neto a Pagar": `base` es el sueldo del
        // expediente, no el bruto del periodo, y el neto real tiene tope en 0 y suma el
        // bono de cumplimiento. Un documento de nómina no puede no cuadrar consigo mismo.
        $totalBase = 0;
        $totalPenalties = 0;
        $totalNet = 0;
        foreach ($payroll as $emp) {
            if (!$emp['salary_pending']) {
                $totalBase += $emp['gross'];
                $totalPenalties += $emp['penalty'];
                $totalNet += $emp['net'];
            }
        }
        $totalBase = round($totalBase, 2);
        $totalPenalties = round($totalPenalties, 2);
        $totalNet = round($totalNet, 2);

        // Un reporte de PRUEBA (datos del Simulador Matrix) salía con el MISMO nombre y el
        // mismo aspecto que el real: imposible distinguir en el escritorio de alguien cuál
        // era cuál. La regla del contrato es que los datos simulados nunca se confundan con
        // los reales, así que el archivo lo dice desde el nombre y desde la portada.
        $esSimulacion = (bool) $request->query('simulation_session_id');
        $prefijo = $esSimulacion ? 'SIMULACION_prenomina_' : 'prenomina_';

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.payroll', [
                'payroll' => $payroll,
                'totalBase' => $totalBase,
                'totalPenalties' => $totalPenalties,
                'totalNet' => $totalNet,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'esSimulacion' => $esSimulacion,
            ]);
            return $pdf->download($prefijo . $startDate . '_' . $endDate . '.pdf');
        }

        // Predeterminado a Excel/XLSX
        // La columna de la firma imprimía el enum crudo de la base ("pending_employee"):
        // el que abre el Excel es el dueño, no un programador.
        $etiquetasFirma = [
            'draft' => 'Borrador (sin cerrar)',
            'pending_employee' => 'Falta firma del colaborador',
            'approved_by_employee' => 'Firmada por el colaborador',
            'approved_by_admin' => 'Autorizada',
            'finalized' => 'Finalizada',
            'stamped' => 'Timbrada',
            'rejected' => 'Rechazada',
        ];

        $exportData = [];
        foreach ($payroll as $emp) {
            $exportData[] = [
                $emp['name'],
                $emp['role'],
                $emp['lates'],
                $emp['absences'],
                // El "Salario Base" de la columna es el BRUTO del periodo, que es contra lo
                // que se restan las deducciones para llegar al neto de la fila de al lado.
                $emp['salary_pending'] ? 'Pendiente' : $emp['gross'],
                $emp['salary_pending'] ? 0 : $emp['penalty'],
                $emp['salary_pending'] ? 'Pendiente' : $emp['net'],
                $etiquetasFirma[$emp['approval_status']] ?? $emp['approval_status'],
            ];
        }

        $encabezadoPeriodo = ($esSimulacion ? 'SIMULACIÓN — ' : '')
            . "Prenómina del {$startDate} al {$endDate}";

        return Excel::download(new class($exportData, $encabezadoPeriodo) implements FromArray, WithHeadings {
            protected $data;
            protected $periodo;
            public function __construct(array $data, string $periodo) { $this->data = $data; $this->periodo = $periodo; }
            public function array(): array { return $this->data; }
            public function headings(): array {
                // El archivo no decía de qué periodo era: dos descargas distintas se veían iguales.
                return [
                    [$this->periodo],
                    ["Colaborador", "Puesto", "Retardos", "Faltas", "Bruto del Periodo", "Penalización", "Neto a Pagar", "Firma Empleado"],
                ];
            }
        }, $prefijo . $startDate . '_' . $endDate . '.xlsx');
    }

    /**
     * Autorización de pago por parte de la EMPRESA.
     *
     * H23: este método era un stub. Devolvía `"Nómina aprobada y lista para timbrar."` **sin
     * tocar la base de datos**, así que la pantalla confirmaba una autorización que no existía:
     * ni estado, ni fecha, ni responsable. Un periodo podía darse por aprobado sin que quedara
     * constancia de quién lo autorizó.
     *
     * Ahora exige que el trabajador haya firmado antes (no se autoriza un cálculo que él no ha
     * visto), deja registrado quién y cuándo, y no permite que nadie autorice su propio pago.
     */
    public function approvePayroll(Request $request)
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|integer',
        ]);

        $actor = auth()->user();
        $tenantId = $actor->tenant_id ?? 1;

        if (!in_array($actor->role ?? '', ['admin', 'supervisor', 'platform_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Sólo un administrador o supervisor puede autorizar el pago de una nómina.',
            ], 403);
        }

        [$startDate, $endDate] = $this->getPeriodDates($request);

        // Sin `employee_id` se autoriza el PERIODO COMPLETO: es lo que hace el botón "Aprobar y
        // Timbrar" del panel de reportes, que no manda a nadie en concreto. Se autorizan sólo las
        // nóminas que el trabajador ya firmó, y nunca la del propio actor.
        if (empty($validated['employee_id'])) {
            return $this->autorizarPeriodoCompleto($tenantId, $actor, $startDate, $endDate);
        }

        // Acotado al tenant: `employee_id` viene del cliente y los ids son globales.
        $empleado = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('id', $validated['employee_id'])
            ->first();

        if (!$empleado) {
            return response()->json([
                'success' => false,
                'message' => 'Colaborador no encontrado en tu empresa.',
            ], 404);
        }

        // Nadie firma el cheque de su propio sueldo: la autorización es el segundo par de ojos.
        if ((int) $empleado->user_id === (int) $actor->id) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes autorizar el pago de tu propia nómina. Debe hacerlo otro administrador.',
            ], 403);
        }

        $nomina = \App\Models\WeeklyPayroll::where('tenant_id', $tenantId)
            ->where('employee_id', $empleado->id)
            ->where('start_date', $startDate)
            ->where('end_date', $endDate)
            ->first();

        if (!$nomina || !$nomina->employee_approved_at) {
            return response()->json([
                'success' => false,
                'message' => 'El colaborador aún no ha firmado de conformidad esta nómina.',
            ], 422);
        }

        // Idempotente: reautorizar no reescribe quién ni cuándo se autorizó la primera vez.
        if ($nomina->status === 'approved_by_admin' && $nomina->admin_approved_at) {
            return response()->json([
                'status' => 'success',
                'message' => 'Esta nómina ya estaba autorizada.',
                'data' => $nomina,
            ]);
        }

        $nomina->update([
            'status' => 'approved_by_admin',
            'admin_approved_at' => Carbon::now(),
            'admin_approved_by' => $actor->id,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Nómina aprobada y lista para timbrar.',
            'data' => $nomina->fresh(),
        ]);
    }

    /**
     * Autoriza de una vez todas las nóminas FIRMADAS del periodo (H23).
     *
     * Se salta a propósito las que el trabajador aún no ha firmado —no se autoriza un cálculo que
     * él no ha visto— y la del propio actor, para que la autorización siga siendo un segundo par
     * de ojos también en el modo masivo. Informa de cuántas quedaron fuera y por qué, en vez de
     * dar un "listo" que oculte que media plantilla sigue pendiente.
     */
    private function autorizarPeriodoCompleto(int $tenantId, $actor, string $startDate, string $endDate)
    {
        $miEmpleadoId = Employee::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $actor->id)
            ->value('id');

        $delPeriodo = \App\Models\WeeklyPayroll::where('tenant_id', $tenantId)
            ->where('start_date', $startDate)
            ->where('end_date', $endDate)
            ->get();

        $autorizadas = 0;
        $sinFirmar = 0;

        foreach ($delPeriodo as $nomina) {
            if ($miEmpleadoId && (int) $nomina->employee_id === (int) $miEmpleadoId) {
                continue; // la propia nunca
            }
            if (!$nomina->employee_approved_at) {
                $sinFirmar++;
                continue;
            }
            if ($nomina->status === 'approved_by_admin' && $nomina->admin_approved_at) {
                continue; // ya autorizada: no se reescribe quién ni cuándo
            }

            $nomina->update([
                'status' => 'approved_by_admin',
                'admin_approved_at' => Carbon::now(),
                'admin_approved_by' => $actor->id,
            ]);
            $autorizadas++;
        }

        // (2026-08-22, fase 11) El mensaje decía SIEMPRE "Nómina aprobada y lista para timbrar",
        // incluso autorizando CERO: con toda la plantilla sin firmar, el administrador leía que su
        // nómina estaba lista cuando no se había autorizado ni una. Ahora el encabezado depende de
        // lo que de verdad pasó.
        if ($delPeriodo->isEmpty()) {
            $mensaje = 'No hay nóminas generadas para este periodo, así que no había nada que autorizar.';
        } elseif ($autorizadas === 0 && $sinFirmar > 0) {
            $mensaje = "No se autorizó ninguna nómina: {$sinFirmar} sigue(n) sin la firma de conformidad del colaborador.";
        } elseif ($autorizadas === 0) {
            $mensaje = 'No había nóminas nuevas que autorizar en este periodo.';
        } else {
            $mensaje = "Nómina autorizada y lista para timbrar. {$autorizadas} autorizada(s).";
            if ($sinFirmar > 0) {
                $mensaje .= " {$sinFirmar} sin autorizar: el colaborador aún no ha firmado de conformidad.";
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => $mensaje,
            'approved' => $autorizadas,
            'pending_employee_signature' => $sinFirmar,
        ]);
    }

    /**
     * Descarga el ticket de nómina de 80 mm para el empleado especificado.
     */
    public function printTicket(Request $request, $employeeId)
    {
        try {
            $tenantId = auth()->user()->tenant_id ?? 1;
            $employee = Employee::where('tenant_id', $tenantId)->where('id', $employeeId)->firstOrFail();
            [$startDate, $endDate] = $this->getPeriodDates($request);

            $payroll = $this->clockService->calculatePayrollForEmployee($employee, $startDate, $endDate);

            $pdf = Pdf::loadView('reports.ticket', [
                'payroll' => $payroll
            ]);

            // Formato de papel ticket 80mm (80 mm por 200 mm)
            $pdf->setPaper([0, 0, 226.77, 566.92]); 

            return $pdf->download('ticket_nomina_' . $employee->id . '_' . now()->format('Ymd') . '.pdf');
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
