<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ProductivityBonusService
 *
 * Calcula y distribuye bonos de productividad basados en el desempeño
 * de los colaboradores en el sistema de TaskRunner (Pilar IV).
 *
 * SPEC: "El sistema de puntos del TaskRunner genera un pool de bonos mensual
 * que se distribuye proporcionalmente al puntaje de cada colaborador."
 *
 * Reglas de negocio:
 * - Cada tenant configura el monto total del pool mensual (default: $1,000 MXN)
 * - El pool se distribuye proporcionalmente: bono = (puntos_empleado / puntos_totales) * pool
 * - Tarea completada a tiempo = puntos completos
 * - Tarea completada tarde = 50% de los puntos
 * - Tarea cancelada = 0 puntos (puede generar penalización)
 * - Se calcula el último día del mes y se refleja en la pre-nómina
 */
class ProductivityBonusService
{
    // =========================================================
    // CALCULAR BONOS DEL MES PARA TODOS LOS EMPLEADOS
    // =========================================================

    /**
     * Calcula el bono de productividad para todos los colaboradores activos
     * de un tenant en el período especificado.
     *
     * @param int    $tenantId
     * @param string $startDate  YYYY-MM-DD (inicio del período — default: inicio del mes)
     * @param string $endDate    YYYY-MM-DD (fin del período — default: fin del mes)
     * @return array  Lista de bonos por empleado ordenada de mayor a menor
     */
    public function calculateMonthlyBonuses(int $tenantId, string $startDate = null, string $endDate = null): array
    {
        $now       = Carbon::now();
        $startDate = $startDate ?? $now->copy()->startOfMonth()->toDateString();
        $endDate   = $endDate   ?? $now->copy()->endOfMonth()->toDateString();

        // Obtener configuración del pool del tenant
        $poolConfig = $this->getPoolConfig($tenantId);
        $totalPool  = $poolConfig['monthly_pool_amount'] ?? 1000.00;

        // Calcular puntos totales del período por empleado
        $employeePoints = $this->calculatePointsByEmployee($tenantId, $startDate, $endDate);

        if (empty($employeePoints)) {
            return [
                'period_start'    => $startDate,
                'period_end'      => $endDate,
                'total_pool'      => $totalPool,
                'total_points'    => 0,
                'bonuses'         => [],
                'message'         => 'No hay actividad de tareas en este período.',
            ];
        }

        // Sumar todos los puntos del período
        $grandTotal = collect($employeePoints)->sum('total_points');

        if ($grandTotal <= 0) {
            return [
                'period_start' => $startDate,
                'period_end'   => $endDate,
                'total_pool'   => $totalPool,
                'total_points' => 0,
                'bonuses'      => [],
                'message'      => 'Ningún colaborador acumuló puntos este período.',
            ];
        }

        // Distribuir el pool proporcionalmente
        $bonuses = collect($employeePoints)->map(function ($emp) use ($totalPool, $grandTotal) {
            $proportion = $emp['total_points'] / $grandTotal;
            $bonusAmount = round($totalPool * $proportion, 2);

            return [
                'employee_id'      => $emp['employee_id'],
                'user_id'          => $emp['user_id'],
                'employee_name'    => $emp['employee_name'],
                'job_role'         => $emp['job_role'],
                'tasks_completed'  => $emp['tasks_completed'],
                'tasks_late'       => $emp['tasks_late'],
                'tasks_cancelled'  => $emp['tasks_cancelled'],
                'total_points'     => $emp['total_points'],
                'proportion_pct'   => round($proportion * 100, 1),
                'bonus_amount'     => $bonusAmount,
                'tier'             => $this->getBonusTier($proportion),
            ];
        })->sortByDesc('bonus_amount')->values()->toArray();

        return [
            'period_start'  => $startDate,
            'period_end'    => $endDate,
            'total_pool'    => $totalPool,
            'total_points'  => $grandTotal,
            'bonuses'       => $bonuses,
        ];
    }

    // =========================================================
    // CALCULAR BONO INDIVIDUAL
    // =========================================================

    /**
     * Calcula el bono de productividad para un empleado específico.
     *
     * @param int    $employeeId
     * @param int    $tenantId
     * @param string $startDate
     * @param string $endDate
     * @return array  {bonus_amount, total_points, tasks_completed, ...}
     */
    public function calculateForEmployee(int $employeeId, int $tenantId, string $startDate, string $endDate): array
    {
        $allBonuses = $this->calculateMonthlyBonuses($tenantId, $startDate, $endDate);

        $empBonus = collect($allBonuses['bonuses'])->firstWhere('employee_id', $employeeId);

        return $empBonus ?? [
            'employee_id'     => $employeeId,
            'tasks_completed' => 0,
            'tasks_late'      => 0,
            'tasks_cancelled' => 0,
            'total_points'    => 0,
            'proportion_pct'  => 0,
            'bonus_amount'    => 0.00,
            'tier'            => 'sin_actividad',
        ];
    }

    // =========================================================
    // GUARDAR BONOS EN PRE-NÓMINA
    // =========================================================

    /**
     * Persistir los bonos calculados en el campo productivity_bonus de WeeklyPayroll.
     * Llamado automáticamente por CalculateWeeklyPayrollCommand.
     */
    public function persistBonusesToPayroll(int $tenantId, string $weekStart, string $weekEnd): int
    {
        $result  = $this->calculateMonthlyBonuses($tenantId, $weekStart, $weekEnd);
        $updated = 0;

        foreach ($result['bonuses'] as $bonus) {
            $rows = DB::table('weekly_payrolls')
                ->where('week_start', $weekStart)
                ->where('employee_id', $bonus['employee_id'])
                ->update([
                    'productivity_bonus' => $bonus['bonus_amount'],
                    'updated_at'         => now(),
                ]);
            $updated += $rows;
        }

        Log::info('ProductivityBonusService: bonos persistidos', [
            'tenant_id'    => $tenantId,
            'week'         => $weekStart,
            'updated_rows' => $updated,
            'total_pool'   => $result['total_pool'],
        ]);

        return $updated;
    }

    // =========================================================
    // RANKING EN TIEMPO REAL (para el Dashboard Monitor)
    // =========================================================

    /**
     * Ranking de productividad del día actual — usado en el monitor en tiempo real.
     *
     * @param int $tenantId
     * @return array  Top N empleados ordenados por puntos del día
     */
    public function getDailyRanking(int $tenantId, int $limit = 10): array
    {
        $today = Carbon::today()->toDateString();

        return DB::table('task_assignments')
            ->join('tasks', 'tasks.id', '=', 'task_assignments.task_id')
            ->join('users', 'users.id', '=', 'task_assignments.user_id')
            ->leftJoin('employees', 'employees.user_id', '=', 'users.id')
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->where('tasks.tenant_id', $tenantId)
            ->whereDate('task_assignments.updated_at', $today)
            ->whereIn('task_assignments.status', ['completed', 'completed_late'])
            ->select(
                'users.id as user_id',
                'users.name as employee_name',
                'job_roles.name as job_role',
                DB::raw('SUM(CASE WHEN task_assignments.status = \'completed\' THEN tasks.points ELSE ROUND(tasks.points * 0.5) END) as total_points'),
                DB::raw('COUNT(CASE WHEN task_assignments.status = \'completed\' THEN 1 END) as on_time'),
                DB::raw('COUNT(CASE WHEN task_assignments.status = \'completed_late\' THEN 1 END) as late')
            )
            ->groupBy('users.id', 'users.name', 'job_roles.name')
            ->orderByDesc('total_points')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    // =========================================================
    // HELPERS PRIVADOS
    // =========================================================

    /**
     * Obtiene puntos totales desglosados por empleado para el período.
     */
    private function calculatePointsByEmployee(int $tenantId, string $startDate, string $endDate): array
    {
        return DB::table('task_assignments')
            ->join('tasks', 'tasks.id', '=', 'task_assignments.task_id')
            ->join('users', 'users.id', '=', 'task_assignments.user_id')
            ->leftJoin('employees', 'employees.user_id', '=', 'users.id')
            ->leftJoin('job_roles', 'job_roles.id', '=', 'employees.job_role_id')
            ->where('tasks.tenant_id', $tenantId)
            ->whereBetween('task_assignments.updated_at', [$startDate . ' 00:00:00', $endDate . ' 23:59:59'])
            ->select(
                'employees.id as employee_id',
                'users.id as user_id',
                'users.name as employee_name',
                'job_roles.name as job_role',
                DB::raw("SUM(CASE
                    WHEN task_assignments.status = 'completed'      THEN tasks.points
                    WHEN task_assignments.status = 'completed_late' THEN ROUND(tasks.points * 0.5)
                    ELSE 0
                END) as total_points"),
                DB::raw("COUNT(CASE WHEN task_assignments.status = 'completed' THEN 1 END) as tasks_completed"),
                DB::raw("COUNT(CASE WHEN task_assignments.status = 'completed_late' THEN 1 END) as tasks_late"),
                DB::raw("COUNT(CASE WHEN task_assignments.status = 'cancelled' THEN 1 END) as tasks_cancelled")
            )
            ->groupBy('employees.id', 'users.id', 'users.name', 'job_roles.name')
            ->having('total_points', '>', 0)
            ->get()
            ->toArray();
    }

    /**
     * Obtiene la configuración del pool de bonos del tenant.
     * Si no existe configuración, usa valores por defecto.
     */
    private function getPoolConfig(int $tenantId): array
    {
        $settings = DB::table('system_settings')
            ->where('tenant_id', $tenantId)
            ->where('key', 'productivity_pool_config')
            ->value('value');

        if ($settings) {
            $decoded = json_decode($settings, true);
            if (is_array($decoded)) return $decoded;
        }

        return [
            'monthly_pool_amount' => 1000.00,
            'enabled'             => true,
            'distribution_mode'   => 'proportional', // 'proportional' | 'tiered'
        ];
    }

    /**
     * Clasifica al empleado en un tier según su proporción del pool.
     */
    private function getBonusTier(float $proportion): string
    {
        if ($proportion >= 0.25)      return 'oro';     // ≥ 25% del pool
        if ($proportion >= 0.15)      return 'plata';   // 15-25%
        if ($proportion >= 0.05)      return 'bronce';  // 5-15%
        return 'participacion';                          // < 5%
    }
}
