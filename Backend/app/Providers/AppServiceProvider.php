<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Billing\BillingProviderInterface::class, function ($app) {
            $provider = env('BILLING_PROVIDER', 'facturapi');
            if ($provider === 'facturapi') {
                return new \App\Services\Billing\FacturapiBillingProvider();
            }
            throw new \Exception("Billing provider [{$provider}] is not supported.");
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // §46: invalidación instantánea del caché de config de /sync/state cuando se
        // editan puestos o políticas de reloj vía Eloquent. Es la vía rápida; el TTL de
        // 5 min de TenantConfigCache es la red de seguridad para cualquier escritura que
        // no pase por estos modelos (ej. syncRbac, que usa query builder e invalida
        // explícitamente en el propio controlador).
        $invalidate = function ($model) {
            if (!empty($model->tenant_id)) {
                \App\Support\TenantConfigCache::forget((int) $model->tenant_id);
            }
        };

        foreach ([\App\Models\JobRole::class, \App\Models\RoleClockPolicy::class] as $modelClass) {
            $modelClass::saved($invalidate);
            $modelClass::deleted($invalidate);
        }
    }
}
