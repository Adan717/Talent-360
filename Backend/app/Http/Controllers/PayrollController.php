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

    public function __construct(ClockService $clockService)
    {
        $this->clockService = $clockService;
    }

    private function getPeriodDates(Request $request)
    {
        $startDate = $request->query('start_date');
        $endDate = $request->query('end_date');

        if (!$startDate || !$endDate) {
            // Predeterminado a la semana actual (Lunes a Domingo)
            $now = Carbon::now();
            $startDate = $now->copy()->startOfWeek()->toDateString();
            $endDate = $now->copy()->endOfWeek()->toDateString();
        }

        return [$startDate, $endDate];
    }

    private function calculatePayrollData(Request $request)
    {
        // Obtener todos los empleados activos del tenant
        $tenantId = auth()->user()->tenant_id ?? 1;
        $employees = Employee::where('tenant_id', $tenantId)
            ->where('is_active_employee', '!=', false)
            ->get();

        [$startDate, $endDate] = $this->getPeriodDates($request);

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
                'lates' => $payroll['incidents']['lates'],
                'absences' => $payroll['incidents']['total_absences'],
                'base' => $payroll['salary']['base'],
                'penalty' => $payroll['deductions_breakdown']['total'],
                'net' => $payroll['salary']['net'],
                'salary_pending' => ($payroll['salary']['base'] === null || $payroll['salary']['base'] <= 0),
                'rest_day_proportion' => $payroll['incidents']['rest_day_proportion'],
                'approval_status' => $payroll['approval']['status']
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
        return response()->json($this->calculatePayrollData($request));
    }

    public function exportReport(Request $request)
    {
        $format = $request->query('format', 'xlsx');
        $payroll = $this->calculatePayrollData($request);
        [$startDate, $endDate] = $this->getPeriodDates($request);

        $totalBase = 0;
        $totalPenalties = 0;
        foreach ($payroll as $emp) {
            if (!$emp['salary_pending']) {
                $totalBase += $emp['base'];
                $totalPenalties += $emp['penalty'];
            }
        }
        $totalNet = $totalBase - $totalPenalties;

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('reports.payroll', [
                'payroll' => $payroll,
                'totalBase' => $totalBase,
                'totalPenalties' => $totalPenalties,
                'totalNet' => $totalNet,
                'startDate' => $startDate,
                'endDate' => $endDate
            ]);
            return $pdf->download('prenomina_' . $startDate . '_' . $endDate . '.pdf');
        }

        // Predeterminado a Excel/XLSX
        $exportData = [];
        foreach ($payroll as $emp) {
            $exportData[] = [
                $emp['name'],
                $emp['role'],
                $emp['lates'],
                $emp['absences'],
                $emp['salary_pending'] ? 'Pendiente' : $emp['base'],
                $emp['salary_pending'] ? 0 : $emp['penalty'],
                $emp['salary_pending'] ? 'Pendiente' : $emp['net'],
                $emp['approval_status']
            ];
        }

        return Excel::download(new class($exportData) implements FromArray, WithHeadings {
            protected $data;
            public function __construct(array $data) { $this->data = $data; }
            public function array(): array { return $this->data; }
            public function headings(): array {
                return ["Colaborador", "Puesto", "Retardos", "Faltas", "Salario Base", "Penalización", "Neto a Pagar", "Firma Empleado"];
            }
        }, 'prenomina_' . $startDate . '_' . $endDate . '.xlsx');
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

        $mensaje = "Nómina aprobada y lista para timbrar. {$autorizadas} autorizada(s).";
        if ($sinFirmar > 0) {
            $mensaje .= " {$sinFirmar} sin autorizar: el colaborador aún no ha firmado de conformidad.";
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
