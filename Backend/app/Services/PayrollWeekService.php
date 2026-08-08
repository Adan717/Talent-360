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

    /**
     * Semana del tenant más reciente que YA TERMINÓ a la fecha dada (la anterior a la que
     * contiene $date). Es el periodo operativo de nómina: lo que se firma, se autoriza y se
     * timbra es siempre una semana completa, nunca una en curso (auditoría N3: calcular la
     * semana corriente contaba los días futuros como faltas y dejaba netos en $0).
     */
    public function lastClosedWeekFor(int $tenantId, Carbon $date): array
    {
        return $this->weekRangeFor($tenantId, $date->copy()->subDays(7));
    }

    /**
     * Ciclo quincenal/mensual (#17, 2026-08-07): el PERIODO de nómina del tenant que
     * contiene la fecha dada, según su periodicidad declarada.
     *
     *   semanal   → su semana configurable (weekRangeFor).
     *   quincenal → quincenas naturales: 1–15 y 16–fin de mes (13 a 16 días según el mes;
     *               es la convención mexicana — los recibos salen el 15 y el último día).
     *   mensual   → mes calendario.
     *
     * Devuelve [inicio 00:00, fin 23:59:59] como weekRangeFor.
     */
    public function periodRangeFor(int $tenantId, Carbon $date): array
    {
        $periodicidad = \App\Http\Controllers\PayrollSettingsController::periodicidadDe($tenantId);
        $d = $date->copy()->startOfDay();

        if ($periodicidad === 'quincenal') {
            $start = $d->day <= 15 ? $d->copy()->startOfMonth() : $d->copy()->startOfMonth()->addDays(15);
            $end = $d->day <= 15 ? $d->copy()->startOfMonth()->addDays(14) : $d->copy()->endOfMonth();
            return [$start->startOfDay(), $end->endOfDay()];
        }

        if ($periodicidad === 'mensual') {
            return [$d->copy()->startOfMonth()->startOfDay(), $d->copy()->endOfMonth()->endOfDay()];
        }

        return $this->weekRangeFor($tenantId, $d);
    }

    /**
     * El periodo de nómina más reciente que YA CERRÓ a la fecha dada: el anterior al que
     * la contiene. Regla N3 generalizada — nunca se opera (batch/firma/autorización/timbre)
     * sobre un periodo en curso.
     */
    public function lastClosedPeriodFor(int $tenantId, Carbon $date): array
    {
        [$start] = $this->periodRangeFor($tenantId, $date);
        return $this->periodRangeFor($tenantId, $start->copy()->subDay());
    }

    /** ¿La semana del tenant CIERRA en la fecha dada? (último día de su semana) */
    public function weekClosesOn(int $tenantId, Carbon $date): bool
    {
        [$start] = $this->weekRangeFor($tenantId, $date);
        $lastDay = $start->copy()->addDays(6)->toDateString();
        return $date->toDateString() === $lastDay;
    }
}
