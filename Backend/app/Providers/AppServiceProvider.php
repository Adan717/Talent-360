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
        //
    }
}
