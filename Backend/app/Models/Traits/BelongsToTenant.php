<?php

namespace App\Models\Traits;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant()
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if ($model instanceof \App\Models\User && $model->role === 'platform_admin') {
                $model->tenant_id = null;
                return;
            }
            if (empty($model->tenant_id)) {
                $user = auth()->user() ?? auth('sanctum')->user();
                if ($user) {
                    $model->tenant_id = $user->tenant_id;
                } else {
                    $model->tenant_id = 1; // FALLBACK TEMPORAL
                }
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
