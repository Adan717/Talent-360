<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\PurgeChatJob;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// =========================================================================
// TAREAS PROGRAMADAS — TALENT360
// =========================================================================

/**
 * Limpieza diaria de mensajes de chat con más de 7 días de antigüedad.
 * SPEC: "el backend ejecutará una limpieza automática diaria para eliminar
 * de forma definitiva cualquier mensaje con más de 7 días de antigüedad."
 */
Schedule::job(new PurgeChatJob())->dailyAt('03:00')->name('purge-chat-messages');

/**
 * Cálculo automático de nómina semanal — ejecuta cada sábado a las 23:59
 * para preparar los recibos de la semana para firma de los empleados.
 */
Schedule::command('payroll:calculate-weekly')
    ->weeklyOn(6, '23:59')
    ->name('weekly-payroll-calculation')
    ->withoutOverlapping();

