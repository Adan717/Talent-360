<?php

namespace App\Traits;

use App\Scopes\TenantScope;

trait Tenantable
{
    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (!$model->tenant_id) {
                if ($model instanceof \App\Models\User && ($model->role === 'platform_admin' || $model->role === 'admin')) {
                    if (!session('tenant_id') && !request()->header('X-Tenant-ID')) {
                        $model->tenant_id = null;
                        return;
                    }
                }
                
                $user = auth()->user() ?? auth('sanctum')->user();
                if ($user && $user->tenant_id) {
                    $model->tenant_id = $user->tenant_id;
                } else {
                    $model->tenant_id = request()->header('X-Tenant-ID', 1);
                }
            }
        });
    }

    /**
     * Get the tenant that owns the model.
     */
    public function tenant()
    {
        return $this->belongsTo(\App\Models\Tenant::class);
    }
}
