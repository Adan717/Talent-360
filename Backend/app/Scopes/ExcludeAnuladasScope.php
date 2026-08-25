<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Un fichaje ANULADO no existe para lo que calcula (2026-08-25).
 *
 * Sigue en la base —la regla de póliza contable: se anula, no se borra— pero la nómina, los
 * reportes y el Monitor tienen que ver la jornada corregida, no las dos versiones. `anulado_at`
 * nulo = fichaje vigente.
 *
 * Para reconstruir la historia completa (una auditoría, un juicio) se bypasea explícitamente con
 * `withoutGlobalScope(ExcludeAnuladasScope::class)`, igual que se hace con el Simulador Matrix.
 * Mismo patrón, misma razón: el default es lo seguro y salirse de él tiene que ser deliberado.
 */
class ExcludeAnuladasScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable() . '.anulado_at');
    }
}
