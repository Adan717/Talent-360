<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evaluación 360: las dos columnas que el código daba por existentes (Plan A5, 2026-09-07).
 *
 * `Evaluation360Controller::store` escribía `leadership_score` y `cycle_month` desde junio, pero
 * la tabla nunca las tuvo y el modelo no las tenía en `$fillable`, así que Eloquent las tiraba en
 * silencio. Consecuencia: `myResults` y `scores` (los ÚNICOS lectores, y además sin ruta) filtraban
 * por una columna inexistente. La evaluación que la plantilla llenaba al cierre del turno no la
 * leía nadie, y de haberla leído, habría reventado con 500.
 *
 * El relleno de las filas viejas sigue la intención del propio `store`: `leadership_score` caía a
 * `performance_score` cuando no venía, y el ciclo es el mes de `created_at`. Se hace por lotes y
 * con la conexión de la migración (el Migrator la vuelve la conexión por defecto mientras corre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_evaluations', function (Blueprint $table) {
            if (!Schema::hasColumn('performance_evaluations', 'leadership_score')) {
                $table->integer('leadership_score')->nullable();
            }
            if (!Schema::hasColumn('performance_evaluations', 'cycle_month')) {
                $table->string('cycle_month', 7)->nullable()->index();
            }
        });

        DB::table('performance_evaluations')
            ->whereNull('cycle_month')
            ->orderBy('id')
            ->chunkById(500, function ($filas) {
                foreach ($filas as $fila) {
                    DB::table('performance_evaluations')->where('id', $fila->id)->update([
                        'cycle_month' => substr((string) $fila->created_at, 0, 7),
                        'leadership_score' => $fila->leadership_score ?? $fila->performance_score,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('performance_evaluations', function (Blueprint $table) {
            $table->dropColumn(['leadership_score', 'cycle_month']);
        });
    }
};
