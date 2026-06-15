<?php

declare(strict_types=1);

namespace Laracaise\Billing;

use Illuminate\Support\ServiceProvider;
use Laracaise\Billing\Console\Commands\BillingExpireSubscriptionsCommand;
use Laracaise\Billing\Console\Commands\BillingInstallCommand;
use Laracaise\Billing\Console\Commands\BillingProcessRenewalsCommand;
use Laracaise\Billing\Console\Commands\BillingResetUsageCommand;
use Laracaise\Billing\Console\Commands\BillingSyncCommand;
use Laracaise\Billing\Http\Middleware\EnsureFeatureAvailable;
use Laracaise\Billing\Http\Middleware\EnsureNotSuspended;
use Laracaise\Billing\Http\Middleware\EnsureSubscriptionActive;
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
        $this->registerMiddleware();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/laracaise-billing.php' => config_path('laracaise-billing.php'),
            ], 'laracaise-billing-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'laracaise-billing-migrations');

            $this->commands([
                BillingInstallCommand::class,
                BillingSyncCommand::class,
                BillingResetUsageCommand::class,
                BillingExpireSubscriptionsCommand::class,
                BillingProcessRenewalsCommand::class,
            ]);
        }
    }

    private function registerMiddleware(): void
    {
        $router = $this->app['router'];

        $router->aliasMiddleware('billing.active', EnsureSubscriptionActive::class);
        $router->aliasMiddleware('billing.feature', EnsureFeatureAvailable::class);
        $router->aliasMiddleware('billing.not_suspended', EnsureNotSuspended::class);
    }
}
