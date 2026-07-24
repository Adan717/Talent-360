<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * store_daily_opening_statuses: a lo más UN status por (tenant_id, store_id, date).
 *
 * Sin este constraint, una carrera en StoreOpeningService::getTodayOpeningStatus (o el
 * bug de fecha pre-Ronda 2, donde `where('date')` no matcheaba el datetime persistido)
 * podía crear filas duplicadas del mismo día; luego `->first()` elegía una arbitraria y
 * el estado de apertura (responsable, deadline, opened_at) quedaba no determinista.
 *
 * Se deduplica antes de agregar el unique (conservando la fila de menor id por grupo,
 * que es la primera creada y la que efectivamente recibió las transiciones de estado).
 * El agregado del índice único NO requiere ramificar por motor: tanto Postgres como
 * SQLite soportan crear un índice único sobre una tabla existente (a diferencia de
 * dropear un PRIMARY KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Dedupe: conservar MIN(id) por (tenant_id, store_id, date). DELETE con subconsulta
        // sobre la misma tabla, soportado por Postgres y SQLite.
        DB::statement('
            DELETE FROM store_daily_opening_statuses
            WHERE id NOT IN (
                SELECT MIN(id)
                FROM store_daily_opening_statuses
                GROUP BY tenant_id, store_id, date
            )
        ');

        Schema::table('store_daily_opening_statuses', function (Blueprint $table) {
            $table->unique(['tenant_id', 'store_id', 'date'], 'store_daily_status_tenant_store_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('store_daily_opening_statuses', function (Blueprint $table) {
            $table->dropUnique('store_daily_status_tenant_store_date_unique');
        });
    }
};
