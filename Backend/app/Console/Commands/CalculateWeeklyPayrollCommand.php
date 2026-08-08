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
 * Calcula la nómina de todos los empleados activos sobre el último periodo CERRADO de
 * cada tenant según su periodicidad (#17): semanal → su semana configurable; quincenal →
 * quincenas naturales 1-15/16-fin; mensual → mes calendario. Nunca el periodo en curso
 * (auditoría N3: contaba los días futuros como faltas y dejaba borradores con neto $0).
 * Corre cada noche vía el scheduler: los drafts del periodo recién cerrado absorben
 * justificantes/contingencias tardíos hasta que el trabajador firma; lo firmado es
 * inmutable, y un recibo firmado de otra periodicidad que traslape bloquea la generación
 * (cambio de periodicidad sólo hacia adelante, sin doble pago).
 *
 * Uso manual: php artisan payroll:calculate-weekly [--tenant_id=1] [--week=2026-07-07]
 * (--week: se calcula el periodo del tenant que CONTIENE esa fecha)
 */
class CalculateWeeklyPayrollCommand extends Command
{
    protected $signature = 'payroll:calculate-weekly
                            {--tenant_id= : ID del tenant (omitir para todos)}
                            {--week=      : Fecha dentro de la semana a calcular YYYY-MM-DD (default: la última semana cerrada de cada tenant)}';

    protected $description = 'Calcula la nómina semanal LFT para todos los empleados activos';

    public function __construct(protected ClockService $clockService, protected \App\Services\PayrollWeekService $weekService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Con --week se calcula la semana que CONTIENE esa fecha, tal cual (backfill/manual).
        // Sin opción, cada tenant resuelve su última semana cerrada (N3): nunca la corriente.
        $explicitWeek = $this->option('week') ? Carbon::parse($this->option('week')) : null;
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
            // #17 (2026-08-07): el candado de periodicidad se convierte en despachador — cada
            // tenant calcula su último periodo CERRADO según su periodicidad declarada
            // (semanal → su semana configurable; quincenal → 1-15 / 16-fin; mensual → mes).
            // ponytail: corre a diario recalculando el periodo cerrado hasta que se firma (así
            // absorbe justificantes tardíos); si el costo molesta, el upgrade es agendar sólo
            // los días de cierre por periodicidad.
            $periodicidad = \App\Http\Controllers\PayrollSettingsController::periodicidadDe($tenant->id);

            [$weekStart, $weekEnd] = $explicitWeek
                ? $this->weekService->periodRangeFor($tenant->id, $explicitWeek->copy())
                : $this->weekService->lastClosedPeriodFor($tenant->id, Carbon::now());
            $this->info("🧮 Tenant #{$tenant->id} ({$periodicidad}): periodo {$weekStart->toDateString()} → {$weekEnd->toDateString()}");

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

                        // #17 — cambio de periodicidad sólo HACIA ADELANTE (decisión del jefe):
                        // si algún día de este periodo ya está cubierto por un recibo de OTRO
                        // periodo que el trabajador firmó (p. ej. semanas firmadas antes de
                        // volverse quincenal), generarlo pagaría esos días DOS veces. Se omite
                        // este empleado hasta que sus periodos dejen de traslaparse.
                        $traslapado = WeeklyPayroll::where('tenant_id', $tenant->id)
                            ->where('employee_id', $employee->id)
                            ->where('status', '!=', 'draft')
                            ->where('start_date', '<=', $weekEnd->toDateString())
                            ->where('end_date', '>=', $weekStart->toDateString())
                            ->where(function ($q) use ($weekStart, $weekEnd) {
                                $q->where('start_date', '!=', $weekStart->toDateString())
                                    ->orWhere('end_date', '!=', $weekEnd->toDateString());
                            })
                            ->exists();
                        if ($traslapado) {
                            $this->warn("  ⏸ Empleado #{$employee->id}: un recibo firmado de otra "
                                . 'periodicidad traslapa este periodo; no se genera (sin doble pago).');
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
            'ref_date'        => ($explicitWeek ?? Carbon::now())->toDateString(),
            'total_employees' => $totalEmployees,
            'errors'          => $errors,
        ]);

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
