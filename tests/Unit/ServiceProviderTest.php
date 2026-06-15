<?php

declare(strict_types=1);

use Laracaise\Billing\BillingManager;
use Laracaise\Billing\Services\FeatureService;
use Laracaise\Billing\Services\UsageService;

it('registers the config', function () {
    expect(config('laracaise-billing'))->toBeArray();
});

it('exposes a driver config key', function () {
    expect(config('laracaise-billing.driver'))->toBeString();
});

it('reads the default driver from the Laracaise billing env key', function () {
    $config = require __DIR__.'/../../config/laracaise-billing.php';

    expect($config['driver'])->toBe(env('LARACAISE_BILLING_DRIVER', 'manual'));
});

it('has a default currency', function () {
    // The package default is ZAR; tests may override this via defineEnvironment
    expect(config('laracaise-billing.currency'))->toBeString()->not->toBeEmpty();
});

it('prepares paystack config keys without hardcoded credentials', function () {
    $paystack = config('laracaise-billing.drivers.paystack');

    expect($paystack)->toBeArray()
        ->toHaveKeys([
            'public_key',
            'secret_key',
            'webhook_secret',
            'base_url',
        ])
        ->and($paystack['public_key'])->not->toBe('pk_test_xxxxx')
        ->and($paystack['secret_key'])->not->toBe('sk_test_xxxxx')
        ->and($paystack['webhook_secret'])->not->toBe('xxxxx');
});

it('registers core services as singletons', function () {
    expect(app(FeatureService::class))->toBe(app(FeatureService::class))
        ->and(app(UsageService::class))->toBe(app(UsageService::class))
        ->and(app(BillingManager::class))->toBe(app(BillingManager::class));
});

it('aliases billing to the billing manager', function () {
    expect(app('billing'))->toBe(app(BillingManager::class));
});
