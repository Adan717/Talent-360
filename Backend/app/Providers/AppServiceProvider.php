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

        // Bloque 6: asistente de reportes. Una sola implementación (OpenAI); en pruebas se
        // sustituye por un doble — mismo patrón que el proveedor de facturación de arriba.
        $this->app->bind(
            \App\Services\ReportIntentParser::class,
            \App\Services\OpenAiReportIntentParser::class
        );
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

        // Academia AC7 (auditoría 2026-08-04): los tokens que emite la Wiki pública
        // (`ObsidianUser`, registro/login de org-vault SIN sesión) son tokens Sanctum
        // normales. El guard `sanctum` de este proyecto no fija `provider`, así que
        // `hasValidProvider()` los daba por buenos y ABRÍAN LA API PRIVADA: quien supiera
        // el slug público de una empresa se registraba solo, obtenía token y hacía
        // POST /employees o PUT /company/payroll-settings. Se cortan aquí, en el guard,
        // que es el único punto por el que pasan todas las rutas `auth:sanctum`.
        // La Wiki sigue funcionando: resuelve su usuario leyendo el token a mano
        // (ObsidianController::resolvePublicUser), sin pasar por este guard.
        \Laravel\Sanctum\Sanctum::authenticateAccessTokensUsing(
            function ($accessToken, bool $isValid) {
                if ($accessToken->tokenable instanceof \App\Models\ObsidianUser) {
                    return false;
                }

                return $isValid;
            }
        );
    }
}
