<?php

declare(strict_types=1);

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
