<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ronda 51 (Reloj): unique (tenant_id, store_id) en `store_opening_settings`.
 *
 * Misma clase que R8 (store_daily_opening_statuses) y R12 (store_opening_assignments): la tabla sólo
 * tenía PK en `id`, y `StoreOpeningSettingsService::getOpeningSettings` hace check-then-insert NO
 * atómico → dos requests concurrentes crean DOS filas para el mismo (tenant, store) y a partir de ahí
 * `->first()` sin ORDER BY es no determinista.
 *
 * En Postgres el daño es concreto: `first()` sin orden hace seq scan en orden FÍSICO y un UPDATE
 * reescribe la fila al final del heap (MVCC) → tras `updateSettings` la fila que el admin acaba de
 * editar se va al final y la siguiente lectura puede devolver la OTRA → sus ajustes "desaparecen" y
 * el reloj obedece defaults. Es el mismo patrón que R46/R47 vinieron cerrando.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Dedupe previo: el unique no se puede crear con duplicados presentes.
        //    Se conserva la fila MÁS RECIENTEMENTE ACTUALIZADA, empate → el `id` menor (el canónico).
        //    Razón: los duplicados sólo pueden nacer de una carrera en la PRIMERA creación (el
        //    servicio ya no crea nada una vez existe una fila), así que ambos nacen con defaults en
        //    el mismo instante; el ÚNICO escritor de `updated_at` a partir de ahí es el admin
        //    guardando desde el panel (`StoreOpeningController::updateSettings`).
        //
        //    Honestidad sobre el alcance: MAX(updated_at) es la intención **más reciente**, no una
        //    "configuración real" reconstruida. Con dos ediciones en competencia (el admin edita una
        //    fila, luego otro PUT aterriza en la otra porque `first()` es no determinista) la config
        //    más vieja se pierde — pero **eso ya se había perdido en producción** en el momento de
        //    esa segunda edición: el reloj obedecía la fila que `first()` devolviera. Ninguna política
        //    de dedupe puede fusionar dos verdades; ésta es la menos mala.
        //
        //    Nota (riesgo aceptado): Postgres ordena NULLS FIRST en DESC, así que una fila con
        //    `updated_at` NULL ganaría. No es alcanzable: los tres caminos de escritura (el seed de
        //    2026_06_28_030000, el `create()` viejo y el `insertOrIgnore` nuevo) siempre lo setean.
        //    Blindarlo exigiría `orderByRaw(... NULLS LAST)`, que rompe la portabilidad a SQLite.
        $duplicados = DB::table('store_opening_settings')
            ->select('tenant_id', 'store_id')
            ->groupBy('tenant_id', 'store_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicados as $grupo) {
            $conservar = DB::table('store_opening_settings')
                ->where('tenant_id', $grupo->tenant_id)
                ->where('store_id', $grupo->store_id)
                ->orderByDesc('updated_at')
                ->orderBy('id')
                ->first();

            if (!$conservar) {
                continue;
            }

            DB::table('store_opening_settings')
                ->where('tenant_id', $grupo->tenant_id)
                ->where('store_id', $grupo->store_id)
                ->where('id', '!=', $conservar->id)
                ->delete();
        }

        // 2. El unique. Compuesto a propósito: multi-sucursal es terreno virgen hoy (`store_id`
        //    siempre vale 1) pero la entidad `stores` va a llegar, y un unique sólo por tenant la
        //    bloquearía.
        Schema::table('store_opening_settings', function (Blueprint $table) {
            $table->unique(['tenant_id', 'store_id'], 'store_opening_settings_tenant_store_unique');
        });
    }

    public function down(): void
    {
        Schema::table('store_opening_settings', function (Blueprint $table) {
            $table->dropUnique('store_opening_settings_tenant_store_unique');
        });
    }
};
