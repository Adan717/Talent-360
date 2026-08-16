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
    protected \App\Services\PayrollWeekService $weekService;

    public function __construct(ClockService $clockService, \App\Services\PayrollWeekService $weekService)
    {
        $this->clockService = $clockService;
        $this->weekService = $weekService;
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
     * Periodo de nómina del tenant que contiene HOY (vista informativa en vivo). #17: para
     * un tenant semanal es su semana configurable; quincenal → 1-15/16-fin; mensual → mes.
     */
    private function currentPeriodRange(int $tenantId): array
    {
        [$start, $end] = $this->weekService->periodRangeFor($tenantId, Carbon::now());
        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * Último periodo CERRADO del tenant: lo único firmable (N3 — firmar un periodo en
     * curso congelaba netos calculados con días futuros contados como faltas).
     */
    private function closedPeriodRange(int $tenantId): array
    {
        [$start, $end] = $this->weekService->lastClosedPeriodFor($tenantId, Carbon::now());
        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * Retorna el historial de asistencias de la semana actual con estado de aprobación diaria.
     */
    public function getDailyRecords(Request $request)
    {
        try {
            $employee = $this->getAuthEmployee();
            [$startDate, $endDate] = $this->currentPeriodRange(auth()->user()->tenant_id ?? 1);

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
     * `?period=closed` devuelve la última semana CERRADA (la firmable); default: la
     * semana en curso, que es sólo vista informativa en vivo.
     */
    public function getPayrollWeekly(Request $request)
    {
        try {
            $employee = $this->getAuthEmployee();
            $tenantId = auth()->user()->tenant_id ?? 1;
            [$startDate, $endDate] = $request->query('period') === 'closed'
                ? $this->closedPeriodRange($tenantId)
                : $this->currentPeriodRange($tenantId);

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
     * Firma de conformidad digital de la nómina semanal.
     *
     * N3: la firma aplica SIEMPRE a la última semana CERRADA del tenant — el servidor decide
     * el periodo, el cliente no manda fechas. Antes se firmaba la semana EN CURSO: un neto de
     * $0 (días futuros contados como faltas) podía quedar congelado a media semana.
     */
    public function approvePayrollWeekly(Request $request)
    {
        try {
            $employee = $this->getAuthEmployee();
            $tenantId = auth()->user()->tenant_id ?? 1;
            [$startDate, $endDate] = $this->closedPeriodRange($tenantId);

            // H22: antes esto recalculaba y sobrescribía la fila SIEMPRE, mirara o no en qué
            // estado estaba. Dos daños reales:
            //  - firmar dos veces PISABA la fecha de la primera firma, así que la constancia de
            //    conformidad pasaba a decir la fecha de la última pulsación;
            //  - una nómina ya autorizada por la empresa volvía a "firmada por el empleado" con
            //    un importe recalculado, sin que quedara rastro.
            $existente = WeeklyPayroll::where('tenant_id', $tenantId)
                ->where('employee_id', $employee->id)
                ->where('start_date', $startDate)
                ->where('end_date', $endDate)
                ->first();

            // Una vez que la empresa autorizó el pago, el periodo está cerrado: la firma del
            // trabajador ya se recogió y volver atrás cambiaría un importe ya aprobado.
            if ($existente && !in_array($existente->status, ['draft', 'pending_employee'], true)
                && $existente->status !== 'approved_by_employee') {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta nómina ya fue autorizada por la empresa y no admite cambios.',
                ], 409);
            }

            // Ya firmada: idempotente. Se devuelve la MISMA fila, con su fecha y su importe
            // originales — que es exactamente lo que el trabajador aceptó.
            if ($existente && $existente->status === 'approved_by_employee' && $existente->employee_approved_at) {
                return response()->json([
                    'success' => true,
                    'message' => 'Esta nómina ya estaba firmada de conformidad.',
                    'data' => $existente,
                ]);
            }

            // Calcular datos finales reales
            $payroll = $this->clockService->calculatePayrollForEmployee($employee, $startDate, $endDate);

            $payrollRecord = WeeklyPayroll::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'employee_id' => $employee->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate
                ],
                // Mismo mapeo que el cálculo nocturno (DesgloseDeNomina): si cada camino
                // guardara lo suyo, el mismo periodo tendría cifras distintas según quién
                // lo escribió — el defecto histórico de este proyecto.
                array_merge(
                    \App\Support\DesgloseDeNomina::paraGuardar($payroll, $employee),
                    [
                        'employee_approved_at' => Carbon::now(),
                        'status' => 'approved_by_employee',
                    ]
                )
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
