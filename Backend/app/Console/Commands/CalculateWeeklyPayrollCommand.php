<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Employee;
use App\Models\WeeklyPayroll;
use App\Services\ClockService;
use Carbon\Carbon;

/**
 * Comando: payroll:calculate-weekly
 *
 * Calcula automáticamente la nómina semanal de todos los empleados activos
 * del tenant. Se ejecuta cada sábado a las 23:59 vía el scheduler de Laravel.
 *
 * Uso manual: php artisan payroll:calculate-weekly [--tenant_id=1] [--week=2026-07-07]
 */
class CalculateWeeklyPayrollCommand extends Command
{
    protected $signature = 'payroll:calculate-weekly
                            {--tenant_id= : ID del tenant (omitir para todos)}
                            {--week=      : Fecha de inicio de semana YYYY-MM-DD (default: semana actual)}';

    protected $description = 'Calcula la nómina semanal LFT para todos los empleados activos';

    public function __construct(protected ClockService $clockService, protected \App\Services\PayrollWeekService $weekService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Fecha de referencia (default: hoy). La semana concreta se resuelve POR TENANT
        // según su día de inicio configurado (Sección 2 #1).
        $refDate  = $this->option('week') ? Carbon::parse($this->option('week')) : Carbon::now();
        $tenantId = $this->option('tenant_id');

        // Obtener tenants activos
        $tenantsQuery = DB::table('tenants')->where('is_active', true);
        if ($tenantId) {
            $tenantsQuery->where('id', $tenantId);
        }
        $tenants = $tenantsQuery->get();

        $totalEmployees = 0;
        $errors         = 0;

        foreach ($tenants as $tenant) {
            // Semana laboral de ESTE tenant (domingo→sábado, lunes→domingo, etc.).
            [$weekStart, $weekEnd] = $this->weekService->weekRangeFor($tenant->id, $refDate->copy());
            $this->info("🧮 Tenant #{$tenant->id}: semana {$weekStart->toDateString()} → {$weekEnd->toDateString()}");

            $employees = Employee::where('is_active_employee', true)
                ->whereHas('user', fn($q) => $q->where('tenant_id', $tenant->id))
                ->with('user')
                ->get();

            foreach ($employees as $employee) {
                try {
                    DB::transaction(function () use ($tenant, $employee, $weekStart, $weekEnd) {
                        // A4 (auditoría 2026-07-27): el comando estaba escrito contra un esquema
                        // IMAGINARIO (week_start/gross_salary/metrics… no existen en la tabla, y
                        // calculatePayrollForEmployee no devuelve gross_total/net_total) — cada
                        // corrida tronaba por-empleado y jamás persistió una pre-nómina. Se
                        // reescribe con el esquema y el contrato REALES (los mismos del flujo de
                        // firma en EmployeePayrollController::signPayroll).
                        //
                        // El lookup va por semana SIN filtrar status: antes, con una fila ya
                        // firmada de la misma semana, el `where status=draft` no la veía y se
                        // creaba una fila DUPLICADA de nómina.
                        $existing = WeeklyPayroll::where('tenant_id', $tenant->id)
                            ->where('employee_id', $employee->id)
                            ->where('start_date', $weekStart->toDateString())
                            ->where('end_date', $weekEnd->toDateString())
                            ->first();

                        // Lo firmado por el empleado es INMUTABLE para el batch nocturno.
                        if ($existing && $existing->status !== 'draft') {
                            return;
                        }

                        $payroll = $this->clockService->calculatePayrollForEmployee(
                            $employee,
                            $weekStart->toDateString(),
                            $weekEnd->toDateString()
                        );

                        WeeklyPayroll::updateOrCreate(
                            [
                                'tenant_id'   => $tenant->id,
                                'employee_id' => $employee->id,
                                'start_date'  => $weekStart->toDateString(),
                                'end_date'    => $weekEnd->toDateString(),
                            ],
                            [
                                'base_salary_paid'    => $payroll['salary']['base'] ?? 0,
                                'lates_count'         => $payroll['incidents']['lates'] ?? 0,
                                'absences_count'      => $payroll['incidents']['total_absences'] ?? 0,
                                'rest_day_proportion' => $payroll['incidents']['rest_day_proportion'] ?? 0,
                                'deductions'          => $payroll['deductions_breakdown']['total'] ?? 0,
                                'net_pay'             => $payroll['salary']['net'] ?? 0,
                                'meal_overtime_mins'  => $payroll['performance']['meal_overtime_mins'] ?? 0,
                                'break_overtime_mins' => $payroll['performance']['break_overtime_mins'] ?? 0,
                                'task_performance_pct' => $payroll['performance']['task_performance_pct'] ?? 0,
                                'performance_score'   => $payroll['performance']['performance_score'] ?? 0,
                                'status'              => 'draft',
                            ]
                        );
                    });

                    $totalEmployees++;
                } catch (\Exception $e) {
                    $errors++;
                    Log::error("CalculateWeeklyPayroll: Error para empleado #{$employee->id}", [
                        'error' => $e->getMessage(),
                    ]);
                    $this->error("  ❌ Empleado #{$employee->id} (" . ($employee->user ? $employee->user->name : 'N/A') . "): {$e->getMessage()}");
                }
            }

            $tenantName = $tenant->name ?? 'Sin nombre';
            $this->info("  ✅ Tenant #{$tenant->id} ({$tenantName}): {$employees->count()} empleados procesados");
        }

        $this->info("\n📊 Resultado: {$totalEmployees} nóminas calculadas, {$errors} errores.");

        Log::info('CalculateWeeklyPayrollCommand completado', [
            'ref_date'        => $refDate->toDateString(),
            'total_employees' => $totalEmployees,
            'errors'          => $errors,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
