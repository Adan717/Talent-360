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
                // §49: el bypass total de aislamiento debe ser exclusivo de cuentas
                // reales de plataforma (instancias de PlatformUser). Se mantiene el
                // camino viejo (una fila de `users` con role='platform_admin') por
                // compatibilidad hacia atrás para no romper nada en producción, pero se
                // registra como deprecado para saber si todavía está en uso — cuando el
                // log confirme que ya no ocurre, se puede quitar la segunda condición.
                if ($user instanceof \App\Models\PlatformUser) {
                    return;
                }
                if ($user->role === 'platform_admin') {
                    \Illuminate\Support\Facades\Log::warning(
                        '[§49] Bypass de TenantScope por una fila de users con role=platform_admin '
                        . '(camino deprecado, debería ser un PlatformUser).',
                        ['user_id' => $user->id ?? null, 'email' => $user->email ?? null]
                    );
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
