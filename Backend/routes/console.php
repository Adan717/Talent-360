<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// =========================================================================
// TAREAS PROGRAMADAS — TALENT360
// =========================================================================

// D3 (2026-08-13, ronda adversarial del bloque 2): aquí vivía PurgeChatJob a las 03:00 —
// una SEGUNDA purga que borraba TODO internal_messages a los 7 días sin filtro alguno
// (pisaba los conservados, los privados, el megáfono y la retención configurada). Es el
// mismo patrón de agenda duplicada que payroll:calculate-weekly (nota de abajo). La única
// purga de chat es `chat:clean-old-messages` (bootstrap/app.php), que sí respeta todo eso.

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


