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

it('has a default currency', function () {
    // The package default is ZAR; tests may override this via defineEnvironment
    expect(config('laracaise-billing.currency'))->toBeString()->not->toBeEmpty();
});

it('registers core services as singletons', function () {
    expect(app(FeatureService::class))->toBe(app(FeatureService::class))
        ->and(app(UsageService::class))->toBe(app(UsageService::class))
        ->and(app(BillingManager::class))->toBe(app(BillingManager::class));
});

it('aliases billing to the billing manager', function () {
    expect(app('billing'))->toBe(app(BillingManager::class));
});
