<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Excluye por defecto cualquier fila generada por el Simulador Matrix de los
 * reportes/consultas reales. NULL en simulation_session_id = dato real.
 * Para "Reportes de Prueba" (ver ClockService::calculatePayrollForEmployee), se
 * bypassa explícitamente con withoutGlobalScope(self::class) + where('simulation_session_id', $id).
 */
class ExcludeSimulationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getTable() . '.simulation_session_id');
    }
}
