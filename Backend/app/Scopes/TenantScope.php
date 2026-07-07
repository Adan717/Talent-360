<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    protected static $resolvingUser = false;

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->runningInConsole()) {
            return;
        }

        if (static::$resolvingUser) {
            return;
        }

        static::$resolvingUser = true;

        try {
            $user = auth()->user() ?? auth('sanctum')->user();
            if ($user) {
                if ($user->role === 'platform_admin') {
                    return;
                }
                if ($user->tenant_id) {
                    $builder->where($model->getTable() . '.tenant_id', $user->tenant_id);
                } else {
                    $builder->whereRaw('1 = 0');
                }
            } else {
                $builder->whereRaw('1 = 0');
            }
        } finally {
            static::$resolvingUser = false;
        }
    }
}
