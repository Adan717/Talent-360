<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de "el encargado ya atendió este caso" en el tablero de pendientes de la Academia.
 *
 * Decisión de producto (2026-08-05): reprobar no bloquea nada, pero el encargado tiene que
 * enterarse. El aviso NO viaja por mensajería —`internal_messages` es el Chat Operativo del
 * Monitor 360, un canal entre personas, y meterle avisos automáticos sería ensuciarlo—, sino
 * como una consulta que el tablero del encargado pide: `GET /supervisor/pendientes`.
 *
 * POR QUÉ ESTA COLUMNA Y NO UNA `supervisor_notified_at`: sin envío no hay nada que
 * deduplicar, y una marca de "ya avisé" no la escribiría nadie — el mismo defecto que acabamos
 * de corregir en `hire_date`, un campo que existía y nadie llenaba. Ésta sí tiene escritor (el
 * encargado, al marcar el caso), propósito (sacar la fila del tablero) y utilidad (un tablero
 * que acumula casos ya resueltos es ruido, no una herramienta de gestión).
 *
 * Regla de la v1, simple a propósito: si el colaborador vuelve a reprobar DESPUÉS de que el
 * encargado marcó atendido, el caso reaparece y el encargado vuelve a marcarlo. No hace falta
 * comparar contra la fecha del último intento.
 *
 * Vive en `user_course_progress` porque ahí ya hay una fila única por (user_id, course_id) —
 * mismo patrón que `coins_awarded` en las seis puertas de Tareas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_course_progress', function (Blueprint $table) {
            $table->timestamp('supervisor_atendido_at')->nullable()->after('failed_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('user_course_progress', function (Blueprint $table) {
            $table->dropColumn('supervisor_atendido_at');
        });
    }
};
