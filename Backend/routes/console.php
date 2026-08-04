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

// A4 (auditoría 2026-07-27): payroll:calculate-weekly estaba agendado DOS veces — aquí
// (sábado fijo 23:59, diseño previo a la semana fiscal por tenant) y en bootstrap/app.php
// (diario 23:00, fiscal-week-aware: recalcula la semana EN CURSO de cada tenant según su
// día de inicio configurado). Se conserva sólo la variante diaria de bootstrap; un sábado
// fijo global contradice la semana configurable (Sección 2 #1).

/**
 * Procesamiento de registros inconclusos y envío de correos de recuperación.
 * Ejecuta cada hora para notificar a prospectos y limpiar registros viejos.
 */
Schedule::command('pending:process-abandoned')
    ->hourly()
    ->name('process-abandoned-registrations')
    ->withoutOverlapping();


