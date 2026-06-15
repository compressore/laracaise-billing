<?php

declare(strict_types=1);

namespace Laracaise\Billing;

use Illuminate\Support\ServiceProvider;

class LaracaiseBillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/laracaise-billing.php',
            'laracaise-billing'
        );
    }

    public function boot(): void
    {
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
