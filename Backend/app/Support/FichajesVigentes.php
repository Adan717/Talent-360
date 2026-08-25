<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * La única puerta de lectura de `time_entries` para lo que CALCULA (2026-08-25).
 *
 * Desde la bitácora inmutable, corregir un fichaje lo **anula**, no lo borra: la fila sigue ahí con
 * su `anulado_at` puesto. El modelo `TimeEntry` ya los descarta con un scope global, pero medio
 * sistema no lee por Eloquent — hay 27 sitios que consultan `DB::table('time_entries')` a pelo
 * (nómina, reportes, Monitor, barridos), y un scope de Eloquent no los toca.
 *
 * Parchear los 27 con un `whereNull` habría funcionado hoy y fallado la próxima vez: el defecto no
 * es que falte un filtro, es que **es posible olvidarlo**. Por eso hay una sola puerta y una prueba
 * de guardia (`LecturasDeFichajesPasanPorLaPuertaTest`) que rechaza cualquier lectura cruda nueva.
 *
 * Para reconstruir la historia completa —una auditoría, un juicio, la pantalla de correcciones—
 * se usa `todos()`, que sí ve los anulados. Que salirse del camino seguro cueste una llamada
 * distinta es justo lo que se busca: obliga a decirlo en voz alta.
 */
class FichajesVigentes
{
    /** Fichajes que cuentan: los que no han sido anulados por una corrección. */
    public static function query(): Builder
    {
        return DB::table('time_entries')->whereNull('time_entries.anulado_at');
    }

    /**
     * TODOS los fichajes, incluidos los anulados. Sólo para auditoría e historia: si esto alimenta
     * un cálculo de nómina o un reporte operativo, se está contando dos veces la misma jornada.
     */
    public static function todos(): Builder
    {
        return DB::table('time_entries');
    }
}
