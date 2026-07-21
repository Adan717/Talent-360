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

    public function approvePayroll(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Nómina aprobada y lista para timbrar.'
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
