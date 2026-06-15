<?php

declare(strict_types=1);

namespace Laracaise\Billing;

use Illuminate\Support\ServiceProvider;
use Laracaise\Billing\Services\FeatureService;
use Laracaise\Billing\Services\UsageService;

class LaracaiseBillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laracaise-billing.php',
            'laracaise-billing'
        );

        $this->app->singleton(FeatureService::class);
        $this->app->singleton(UsageService::class);
        $this->app->singleton(BillingManager::class);
        $this->app->alias(BillingManager::class, 'billing');
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/laracaise-billing.php');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/laracaise-billing.php' => config_path('laracaise-billing.php'),
            ], 'laracaise-billing-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'laracaise-billing-migrations');
        }
    }
}
