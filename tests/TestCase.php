<?php

declare(strict_types=1);

namespace Laracaise\Billing\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
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
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('laracaise-billing.driver', 'null');
        $app['config']->set('laracaise-billing.currency', 'ZAR');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('test_billables', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name')->default('test');
            $table->timestamps();
        });

        $this->beforeApplicationDestroyed(
            fn () => Schema::dropIfExists('test_billables')
        );
    }
}
