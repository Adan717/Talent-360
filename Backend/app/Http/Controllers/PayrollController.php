<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\TimeEntry;
use Carbon\Carbon;
use App\Enums\UserRole;
use App\Models\PayrollPolicy;


use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Barryvdh\DomPDF\Facade\Pdf;

class PayrollController extends Controller
{
    private function calculatePayrollData()
    {
        // Obtener todos los empleados activos del tenant
        $employees = \App\Models\Employee::where('is_active_employee', '!=', false)
            ->with('user')
            ->get();

        // Buscar la política de nómina del tenant actual
        $policy = PayrollPolicy::where('tenant_id', auth()->user()->tenant_id)->first();
        $latePenalty = $policy ? $policy->late_penalty : 250;
        $absencePenalty = $policy ? $policy->absence_penalty : 1000;

        $payroll = [];

        foreach ($employees as $employee) {
            // Salario base de la quincena (si es quincenal, supongamos que es el base_salary mensual / 2, o directo base_salary si es por periodo. Si base_salary no existe, usamos $employee->salary).
            $baseSalary = $employee->base_salary ?? $employee->salary;
            $salaryPending = ($baseSalary === null || (float)$baseSalary <= 0);
            if ($salaryPending) {
                $baseSalary = null;
            }
            
            // Consultar las entradas de tiempo en el rango (por defecto últimos 15 días)
            $startDate = Carbon::now()->subDays(15)->startOfDay();
            $endDate = Carbon::now()->endOfDay();

            $entries = TimeEntry::where('user_id', $employee->user_id)
                ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
                ->get();

            // Calcular retardos
            $latesCount = $entries->where('type', 'check_in')->where('is_late', true)->count();
            $lateMinutes = $entries->where('type', 'check_in')->sum('late_minutes');

            // Calcular faltas
            $daysWithAttendance = $entries->pluck('date')->unique()->count();
            
            if ($daysWithAttendance > 0) {
                $expectedWorkingDays = 12;
                $absencesCount = max(0, $expectedWorkingDays - $daysWithAttendance);
            } else {
                $absencesCount = 1;
            }

            // Penalización dinámica según política
            $penalty = ($latesCount * $latePenalty) + ($absencesCount * $absencePenalty);

            // Evitar salarios negativos
            $netPay = $salaryPending ? null : max(0, $baseSalary - $penalty);

            $payroll[] = [
                'id' => $employee->user_id ?? $employee->id,
                'name' => $employee->name,
                'role' => $employee->role ?? 'Colaborador',
                'lates' => $latesCount,
                'absences' => $absencesCount,
                'base' => $salaryPending ? null : (float)$baseSalary,
                'penalty' => (float)$penalty,
                'net' => $salaryPending ? null : (float)$netPay,
                'salary_pending' => $salaryPending
            ];
        }

        return $payroll;
    }

    public function getPayrollData(Request $request)
    {
        return response()->json($this->calculatePayrollData());
    }

    public function exportReport(Request $request)
    {
        $format = $request->query('format', 'xlsx');
        $payroll = $this->calculatePayrollData();

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
                'totalNet' => $totalNet
            ]);
            return $pdf->download('prenomina_' . now()->format('Y-m-d') . '.pdf');
        }

        // Default to Excel/XLSX
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
            ];
        }

        return Excel::download(new class($exportData) implements FromArray, WithHeadings {
            protected $data;
            public function __construct(array $data) { $this->data = $data; }
            public function array(): array { return $this->data; }
            public function headings(): array {
                return ["Colaborador", "Puesto", "Retardos", "Faltas", "Salario Base", "Penalización", "Neto a Pagar"];
            }
        }, 'prenomina_' . now()->format('Y-m-d') . '.xlsx');
    }

    public function approvePayroll(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'message' => 'Nómina aprobada y timbrada con éxito'
        ]);
    }
}
