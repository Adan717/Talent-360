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
                    DB::transaction(function () use ($employee, $weekStart, $weekEnd) {
                        // Verificar si ya existe un cálculo para esta semana
                        $existing = WeeklyPayroll::where('employee_id', $employee->id)
                            ->where('week_start', $weekStart->toDateString())
                            ->where('status', 'draft')
                            ->first();

                        if ($existing) {
                            // Recalcular si está en draft
                            $payroll = $this->clockService->calculatePayrollForEmployee(
                                $employee,
                                $weekStart->toDateString(),
                                $weekEnd->toDateString()
                            );

                            $existing->update([
                                'gross_salary'     => $payroll['gross_total']   ?? 0,
                                'deductions'       => $payroll['total_deductions'] ?? 0,
                                'net_salary'       => $payroll['net_total']     ?? 0,
                                'worked_days'      => $payroll['worked_days']   ?? 0,
                                'late_count'       => $payroll['late_count']    ?? 0,
                                'absence_count'    => $payroll['absence_count'] ?? 0,
                                'productivity_bonus' => $payroll['bonus_total'] ?? 0,
                                'metrics'          => json_encode($payroll),
                                'updated_at'       => now(),
                            ]);
                        } else {
                            // Crear nuevo registro de nómina
                            $payroll = $this->clockService->calculatePayrollForEmployee(
                                $employee,
                                $weekStart->toDateString(),
                                $weekEnd->toDateString()
                            );

                            WeeklyPayroll::create([
                                'employee_id'       => $employee->id,
                                'week_start'        => $weekStart->toDateString(),
                                'week_end'          => $weekEnd->toDateString(),
                                'gross_salary'      => $payroll['gross_total']    ?? 0,
                                'deductions'        => $payroll['total_deductions'] ?? 0,
                                'net_salary'        => $payroll['net_total']      ?? 0,
                                'worked_days'       => $payroll['worked_days']    ?? 0,
                                'late_count'        => $payroll['late_count']     ?? 0,
                                'absence_count'     => $payroll['absence_count']  ?? 0,
                                'productivity_bonus'=> $payroll['bonus_total']    ?? 0,
                                'status'            => 'draft',
                                'metrics'           => json_encode($payroll),
                                // Snapshot inmutable (Directiva 2: Inmutabilidad Histórica)
                                'salary_at_time'          => $employee->daily_salary ?? 0,
                                'job_role_title_at_time'  => $employee->jobRole?->name ?? 'N/A',
                                'employee_name_at_time'   => $employee->user?->name    ?? 'N/A',
                            ]);
                        }
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
