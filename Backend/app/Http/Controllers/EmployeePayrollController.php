<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Employee;
use App\Models\DailyApproval;
use App\Models\WeeklyPayroll;
use App\Services\ClockService;
use Carbon\Carbon;

class EmployeePayrollController extends Controller
{
    protected ClockService $clockService;

    public function __construct(ClockService $clockService)
    {
        $this->clockService = $clockService;
    }

    /**
     * Helper para obtener el empleado autenticado o lanzar error.
     */
    private function getAuthEmployee()
    {
        $user = auth()->user();
        if (!$user) {
            throw new \Exception('Usuario no autenticado.');
        }
        $employee = Employee::where('user_id', $user->id)->first();
        if (!$employee) {
            throw new \Exception('No se encontró expediente de colaborador para este usuario.');
        }
        return $employee;
    }

    /**
     * Helper para obtener el rango de fechas de la semana actual (Lunes a Domingo).
     */
    private function getCurrentWeekRange()
    {
        $now = Carbon::now();
        $start = $now->copy()->startOfWeek(); // Lunes
        $end = $now->copy()->endOfWeek(); // Domingo
        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * Retorna el historial de asistencias de la semana actual con estado de aprobación diaria.
     */
    public function getDailyRecords(Request $request)
    {
        try {
            $employee = $this->getAuthEmployee();
            [$startDate, $endDate] = $this->getCurrentWeekRange();

            $payroll = $this->clockService->calculatePayrollForEmployee($employee, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $payroll['days_details']
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Firma/aprueba el registro de asistencia del colaborador para una fecha específica.
     */
    public function approveDailyRecord(Request $request)
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'status' => 'required|string|in:approved,disputed',
            'comments' => 'nullable|string|max:500'
        ]);

        try {
            $employee = $this->getAuthEmployee();
            $tenantId = auth()->user()->tenant_id ?? 1;

            $approval = DailyApproval::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'employee_id' => $employee->id,
                    'date' => $validated['date']
                ],
                [
                    'status' => $validated['status'],
                    'comments' => $validated['comments'] ?? null
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Estatus de registro diario actualizado correctamente.',
                'data' => $approval
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Retorna el desglose de nómina semanal y estado de aprobación del colaborador.
     */
    public function getPayrollWeekly(Request $request)
    {
        try {
            $employee = $this->getAuthEmployee();
            [$startDate, $endDate] = $this->getCurrentWeekRange();

            $payroll = $this->clockService->calculatePayrollForEmployee($employee, $startDate, $endDate);

            return response()->json([
                'success' => true,
                'data' => $payroll
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Firma de conformidad digital la nómina semanal el sábado.
     */
    public function approvePayrollWeekly(Request $request)
    {
        try {
            $employee = $this->getAuthEmployee();
            $tenantId = auth()->user()->tenant_id ?? 1;
            [$startDate, $endDate] = $this->getCurrentWeekRange();

            // Calcular datos finales reales
            $payroll = $this->clockService->calculatePayrollForEmployee($employee, $startDate, $endDate);

            $payrollRecord = WeeklyPayroll::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'employee_id' => $employee->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                [
                    'base_salary_paid' => $payroll['salary']['base'],
                    'lates_count' => $payroll['incidents']['lates'],
                    'absences_count' => $payroll['incidents']['total_absences'],
                    'rest_day_proportion' => $payroll['incidents']['rest_day_proportion'],
                    'deductions' => $payroll['deductions_breakdown']['total'],
                    'net_pay' => $payroll['salary']['net'],
                    'employee_approved_at' => Carbon::now(),
                    'status' => 'approved_by_employee'
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Nómina firmada y aceptada de conformidad exitosamente.',
                'data' => $payrollRecord
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
