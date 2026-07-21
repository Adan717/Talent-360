<?php

namespace App\Traits;

use App\Scopes\ExcludeSimulationScope;

trait ExcludesSimulationData
{
    /**
     * Convención boot{TraitName} de Eloquent: se usa en vez de sobreescribir booted()
     * para no chocar con el booted() que ya define el trait Tenantable en estos mismos
     * modelos — Eloquent invoca automáticamente boot<TraitName>() de cada trait.
     */
    protected static function bootExcludesSimulationData(): void
    {
        static::addGlobalScope(new ExcludeSimulationScope);
    }
}
