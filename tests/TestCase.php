<?php

declare(strict_types=1);

namespace Laracaise\Billing\Tests;

use Laracaise\Billing\LaracaiseBillingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaracaiseBillingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('laracaise-billing.driver', 'paystack');
        $app['config']->set('laracaise-billing.currency', 'ZAR');
    }
}
