<?php

namespace App\Traits;

use App\Scopes\ExcludeAnuladasScope;

/**
 * Un fichaje ANULADO no existe para lo que calcula (2026-08-25). Sigue en la base —se anula, no se
 * borra— pero la nómina, los reportes y el Monitor ven la jornada corregida. Para reconstruir la
 * historia completa: `withoutGlobalScope(ExcludeAnuladasScope::class)`.
 *
 * POR QUÉ ES UN TRAIT Y NO UN `booted()` EN EL MODELO (2026-08-28): así nació, dentro de un
 * `protected static function booted()` en el cuerpo de `TimeEntry`. En PHP el método de la CLASE
 * gana sobre el del trait, así que ese `booted()` dejó mudo al de `Tenantable` — y con él se
 * fueron, sin que nadie lo notara, el `TenantScope` y el hook `creating` que rellena `tenant_id`.
 * Comprobado en ejecución: `TimeEntry` tenía sólo los scopes de simulación y anuladas, mientras
 * `Employee` (mismo trait, sin `booted()` propio) sí conservaba el suyo.
 *
 * No hubo fuga: todas las lecturas de `time_entries` filtran a mano por `tenant_id` o por
 * `user_id` (y un usuario pertenece a una sola empresa). Era una red de seguridad que llevaba
 * meses descolgada en la tabla más sensible del producto, no un agujero abierto.
 *
 * La convención `boot{NombreDelTrait}()` es la salida: Eloquent la invoca en cada trait, así que
 * los scopes se SUMAN en vez de pisarse. El trait hermano `ExcludesSimulationData` ya documentaba
 * esta misma trampa (líneas 10-13) — el scope de anuladas, escrito después, cayó en ella igual.
 */
trait ExcluyeAnuladas
{
    protected static function bootExcluyeAnuladas(): void
    {
        static::addGlobalScope(new ExcludeAnuladasScope());
    }
}
