<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cierra la ventana de carrera que el guard de aplicación en ClockService::processPunch()
     * no puede cerrar por sí solo: dos peticiones casi simultáneas podrían ambas pasar el
     * exists() antes de que la primera confirme su insert. Antes de poder agregar el índice
     * único hay que deduplicar filas históricas que ya puedan existir (se conserva la más
     * antigua por id, que es la que reflejaba el fichaje real; las posteriores son el bug).
     */
    public function up(): void
    {
        $duplicateGroups = DB::table('time_entries')
            ->select('user_id', 'date', 'type')
            ->groupBy('user_id', 'date', 'type')
            ->havingRaw('count(*) > 1')
            ->count();

        if ($duplicateGroups > 0) {
            Log::warning("[migration] Deduplicando {$duplicateGroups} grupos de fichajes duplicados en time_entries antes de agregar el índice único.");
        }

        DB::statement('
            DELETE FROM time_entries
            WHERE id NOT IN (
                SELECT MIN(id) FROM time_entries GROUP BY user_id, date, type
            )
        ');

        Schema::table('time_entries', function (Blueprint $table) {
            $table->unique(['user_id', 'date', 'type'], 'time_entries_user_date_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropUnique('time_entries_user_date_type_unique');
        });
    }
};
