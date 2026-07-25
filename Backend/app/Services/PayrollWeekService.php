<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Sección 2 #1: resuelve la semana laboral de cada empresa según su configuración
 * (día de inicio de semana, día de pago, hora de cálculo), respetando la LFT (pago
 * al menos semanal) pero permitiendo que cada tenant lo ajuste.
 *
 * Config por tenant (system_settings):
 *   payroll_week_start_day : 0..6 (0=domingo, 1=lunes, ..., 6=sábado). Default global: 1 (lunes).
 *   payroll_pay_day        : 0..6. Default global: 5 (viernes).
 *   payroll_calc_time      : "HH:MM". Default global: "23:00".
 * El cálculo automático corre al cerrar la semana (último día de la semana del tenant).
 */
class PayrollWeekService
{
    public const DEFAULT_WEEK_START = 1; // lunes
    public const DEFAULT_PAY_DAY = 5;    // viernes
    public const DEFAULT_CALC_TIME = '23:00';

    /** Mapea 0..6 (domingo..sábado) al día de Carbon (Carbon::SUNDAY=0..Carbon::SATURDAY=6). */
    private function carbonDay(int $day): int
    {
        // Carbon usa SUNDAY=0 .. SATURDAY=6, igual que nuestra convención.
        return max(0, min(6, $day));
    }

    private function setting(int $tenantId, string $key, $default)
    {
        $raw = DB::table('system_settings')->where('tenant_id', $tenantId)->where('key', $key)->value('value');
        if ($raw === null) {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return $decoded === null ? $raw : $decoded;
    }

    public function weekStartDay(int $tenantId): int
    {
        return (int) $this->setting($tenantId, 'payroll_week_start_day', self::DEFAULT_WEEK_START);
    }

    public function payDay(int $tenantId): int
    {
        return (int) $this->setting($tenantId, 'payroll_pay_day', self::DEFAULT_PAY_DAY);
    }

    public function calcTime(int $tenantId): string
    {
        return (string) $this->setting($tenantId, 'payroll_calc_time', self::DEFAULT_CALC_TIME);
    }

    /**
     * Devuelve [inicio, fin] (Carbon, a medianoche) de la semana del tenant que
     * contiene la fecha dada, según su día de inicio configurado.
     */
    public function weekRangeFor(int $tenantId, Carbon $date): array
    {
        $startDay = $this->carbonDay($this->weekStartDay($tenantId));
        $start = $date->copy()->startOfDay();

        // Retroceder hasta el día de inicio de semana del tenant.
        while ((int) $start->dayOfWeek !== $startDay) {
            $start->subDay();
        }

        $end = $start->copy()->addDays(6)->endOfDay();

        return [$start->copy()->startOfDay(), $end];
    }

    /** ¿La semana del tenant CIERRA en la fecha dada? (último día de su semana) */
    public function weekClosesOn(int $tenantId, Carbon $date): bool
    {
        [$start] = $this->weekRangeFor($tenantId, $date);
        $lastDay = $start->copy()->addDays(6)->toDateString();
        return $date->toDateString() === $lastDay;
    }
}
