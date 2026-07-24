<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merge F3 (ARBITRAJE): retira el índice UNIQUE(user_id, date, type) de time_entries.
 *
 * El índice (migración 2026_07_21_000005) imponía "un ponche por tipo por día", lo que prohíbe dos
 * flujos LEGÍTIMOS de la línea del Reloj (R63/R68/R75, cubiertos por PayrollSplitShiftTest y el
 * pareo LIFO de pausas):
 *  - el turno PARTIDO: check_in → check_out → check_in el mismo día;
 *  - las pausas MÚLTIPLES: varios pares meal/break/temp_exit en la jornada.
 *
 * La protección anti-duplicados NO desaparece: la máquina de estados de ClockService::processPunch
 * rechaza el check_in con turno abierto (idempotente), exige el predecesor lógico de cada tipo
 * (meal_end sin meal_start, meal_start tras cerrar el día, etc.) y la cola offline es idempotente
 * por client_stamp. La única brecha restante es una race verdaderamente simultánea del mismo
 * usuario — artefacto de estrés, no uso real (decisión documentada R63; la línea del Reloj ya
 * había evaluado y descartado este mismo índice por el turno partido).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropUnique('time_entries_user_date_type_unique');
        });
        // Índice NO-único de reemplazo: conserva el plan de consulta de la máquina de estados
        // (user_id, date, type es el filtro de cada guard) sin imponer la unicidad.
        Schema::table('time_entries', function (Blueprint $table) {
            $table->index(['user_id', 'date', 'type'], 'time_entries_user_date_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex('time_entries_user_date_type_idx');
            $table->unique(['user_id', 'date', 'type'], 'time_entries_user_date_type_unique');
        });
    }
};
