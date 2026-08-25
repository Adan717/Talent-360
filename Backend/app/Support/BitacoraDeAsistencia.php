<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * El puente entre la intención de la aplicación y el trigger de la base (2026-08-25).
 *
 * El trigger que llena `time_entries_historial` sabe *qué* cambió, pero no *quién lo pidió ni por
 * qué* — eso es información de negocio y una función de base de datos no tiene por qué conocerla.
 * Aquí la aplicación la deja escrita en variables de la TRANSACCIÓN (`SET LOCAL`), y el trigger la
 * recoge con `current_setting(..., true)`.
 *
 * `SET LOCAL` y no `SET` a secas: la vida de esas variables es la de la transacción. Con la
 * conexión reutilizada por el pool, un `SET` normal dejaría al siguiente cambio —de otra petición,
 * de otro usuario— firmado con el actor anterior. Una bitácora que atribuye mal es peor que no
 * tenerla.
 *
 * Fuera de Postgres no hace nada: la suite corre en sqlite, que ni tiene el trigger ni entiende
 * estas variables. Ahí la bitácora se prueba contra el Postgres real.
 */
class BitacoraDeAsistencia
{
    /**
     * Ejecuta $trabajo dentro de una transacción firmada: todo cambio que toque `time_entries`
     * dentro de ella queda en el historial con este actor, esta corrección y este origen.
     *
     * @template T
     * @param  callable():T  $trabajo
     * @return T
     */
    public static function firmando(?int $actorId, string $origen, ?int $correccionId, callable $trabajo)
    {
        return DB::transaction(function () use ($actorId, $origen, $correccionId, $trabajo) {
            self::declarar($actorId, $origen, $correccionId);

            return $trabajo();
        });
    }

    /**
     * Declara la intención en la transacción EN CURSO. Úsese cuando ya se está dentro de una
     * transacción propia; si no, `firmando()` es más seguro porque garantiza que la haya.
     */
    public static function declarar(?int $actorId, string $origen, ?int $correccionId = null): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Valores parametrizados vía set_config (SET LOCAL no acepta parámetros enlazados, y
        // concatenarlos a mano en SQL sería una inyección esperando a ocurrir).
        DB::statement('SELECT set_config(?, ?, true)', ['app.actor_id', $actorId !== null ? (string) $actorId : '']);
        DB::statement('SELECT set_config(?, ?, true)', ['app.origen', substr($origen, 0, 80)]);
        DB::statement('SELECT set_config(?, ?, true)', ['app.correccion_id', $correccionId !== null ? (string) $correccionId : '']);
    }
}
