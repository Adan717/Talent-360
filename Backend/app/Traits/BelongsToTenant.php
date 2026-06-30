<?php

namespace App\Traits;

use App\Scopes\TenantScope;

trait BelongsToTenant
{
    /**
     * Boot the trait for a model.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if ($model instanceof \App\Models\User && $model->role === 'platform_admin') {
                $model->tenant_id = null;
                return;
            }
            if (empty($model->tenant_id)) {
                $user = auth()->user() ?? auth('sanctum')->user();
                $model->tenant_id = $user ? $user->tenant_id : 1;
            }
        });
    }

    /**
     * Relación con la Empresa
     */
    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
