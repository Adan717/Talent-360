<?php

namespace App\Support;

/**
 * Traduce el resultado de `ClockService::calculatePayrollForEmployee` a las columnas de
 * `weekly_payrolls` (2026-08-16).
 *
 * Existe porque hay DOS puntos que guardan un recibo —el cálculo nocturno y la firma del
 * colaborador— y hasta ahora cada uno repetía el mapeo a mano. Con el desglose nuevo eso
 * garantizaba que un concepto acabara guardado por un camino y no por el otro: el mismo
 * periodo tendría cifras distintas según quién lo guardó. Ahora los dos llaman aquí.
 */
class DesgloseDeNomina
{
    /**
     * @param array $payroll lo que devuelve calculatePayrollForEmployee
     * @param object|null $employee para el snapshot del puesto en el momento del recibo
     */
    public static function paraGuardar(array $payroll, $employee = null): array
    {
        $puesto = $employee?->jobRole ?? null;

        return [
            // Lo que ya se guardaba (mismos valores, mismo origen).
            'base_salary_paid' => $payroll['salary']['base'] ?? 0,
            'lates_count' => $payroll['incidents']['lates'] ?? 0,
            'absences_count' => $payroll['incidents']['total_absences'] ?? 0,
            'rest_day_proportion' => $payroll['incidents']['rest_day_proportion'] ?? 0,
            'deductions' => $payroll['deductions_breakdown']['total'] ?? 0,
            'net_pay' => $payroll['salary']['net'] ?? 0,
            'meal_overtime_mins' => $payroll['performance']['meal_overtime_mins'] ?? 0,
            'break_overtime_mins' => $payroll['performance']['break_overtime_mins'] ?? 0,
            'task_performance_pct' => $payroll['performance']['task_performance_pct'] ?? 0,
            'performance_score' => $payroll['performance']['performance_score'] ?? 0,

            // El desglose que antes se tiraba.
            'gross_pay' => $payroll['salary']['gross'] ?? null,
            'holiday_bonus_pay' => $payroll['salary']['holiday_bonus'] ?? null,
            'punctuality_bonus' => $payroll['bonus']['punctuality_amount'] ?? null,
            'opening_bonus' => $payroll['bonus']['opening_amount'] ?? null,
            'deduction_absences' => $payroll['deductions_breakdown']['absences'] ?? null,
            'deduction_rest_day' => $payroll['deductions_breakdown']['rest_day'] ?? null,
            'deduction_lates' => $payroll['deductions_breakdown']['lates'] ?? null,
            'daily_salary' => $payroll['salary']['daily'] ?? null,

            // Puesto y área EN EL MOMENTO: si la persona cambia de puesto después, el costo
            // histórico debe seguir contando en el área donde de verdad se gastó.
            'job_role_title_at_time' => $puesto->name ?? null,
            'job_role_area_at_time' => $puesto->area ?? null,
        ];
    }
}
