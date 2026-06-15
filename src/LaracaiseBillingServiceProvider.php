<?php

declare(strict_types=1);

namespace Laracaise\Billing;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Laracaise\Billing\Console\Commands\BillingExpireSubscriptionsCommand;
use Laracaise\Billing\Console\Commands\BillingInstallCommand;
use Laracaise\Billing\Console\Commands\BillingProcessRenewalsCommand;
use Laracaise\Billing\Console\Commands\BillingResetUsageCommand;
use Laracaise\Billing\Console\Commands\BillingSyncCommand;
use Laracaise\Billing\Http\Middleware\MiddlewareAliasRegistrar;
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
        MiddlewareAliasRegistrar::registerOnRouter($this->app->make(Router::class));

        // Also register on the kernel when it is first resolved. Testbench's
        // resolveApplicationHttpMiddlewares() hook resets $middlewareAliases on
        // the kernel after the service provider boots; calling afterResolving here
        // ensures billing aliases survive that reset in all bootstrap contexts.
        $this->app->afterResolving(
            HttpKernelContract::class,
            fn ($kernel) => MiddlewareAliasRegistrar::registerOnKernel($kernel),
        );
    }
}
