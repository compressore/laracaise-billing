<?php

declare(strict_types=1);

it('registers the config', function () {
    expect(config('laracaise-billing'))->toBeArray();
});

it('has a default driver', function () {
    expect(config('laracaise-billing.driver'))->toBe('paystack');
});

it('has a default currency', function () {
    expect(config('laracaise-billing.currency'))->toBe('ZAR');
});
