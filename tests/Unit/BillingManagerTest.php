<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Laracaise\Billing\BillingContext;
use Laracaise\Billing\BillingManager;
use Laracaise\Billing\Contracts\PaymentDriverInterface;
use Laracaise\Billing\Drivers\ManualDriver;
use Laracaise\Billing\Drivers\PaystackDriver;
use Laracaise\Billing\Facades\Billing;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Tests\Fixtures\BillableModel;

it('creates a billing context for an entity', function () {
    $entity = BillableModel::create(['name' => 'Acme']);

    expect(app(BillingManager::class)->for($entity))->toBeInstanceOf(BillingContext::class);
});

it('resolves the configured manual payment driver', function () {
    config()->set('laracaise-billing.driver', 'manual');

    expect(app(BillingManager::class)->driver())
        ->toBeInstanceOf(PaymentDriverInterface::class)
        ->toBeInstanceOf(ManualDriver::class);
});

it('resolves a manual payment driver by explicit name', function () {
    expect(app(BillingManager::class)->driver('manual'))->toBeInstanceOf(ManualDriver::class);
});

it('resolves a paystack payment driver by explicit name', function () {
    expect(app(BillingManager::class)->driver('paystack'))->toBeInstanceOf(PaystackDriver::class);
});

it('rejects unsupported payment drivers', function () {
    app(BillingManager::class)->driver('unsupported-driver');
})->throws(InvalidArgumentException::class);

it('returns an active plan by slug', function () {
    $plan = Plan::factory()->create(['slug' => 'pro']);

    expect(app(BillingManager::class)->plan('pro')?->is($plan))->toBeTrue();
});

it('does not return inactive plans by slug', function () {
    Plan::factory()->inactive()->create(['slug' => 'archived']);

    expect(app(BillingManager::class)->plan('archived'))->toBeNull();
});

it('returns active plans in display order', function () {
    $last = Plan::factory()->create(['name' => 'Zulu', 'sort_order' => 20]);
    $first = Plan::factory()->create(['name' => 'Alpha', 'sort_order' => 10]);
    $middle = Plan::factory()->create(['name' => 'Bravo', 'sort_order' => 10]);
    Plan::factory()->inactive()->create(['name' => 'Archived', 'sort_order' => 0]);

    $plans = app(BillingManager::class)->plans();

    expect($plans)->toBeInstanceOf(Collection::class)
        ->and($plans->pluck('id')->all())->toBe([
            $first->id,
            $middle->id,
            $last->id,
        ]);
});

it('exposes manager methods through the facade', function () {
    $plan = Plan::factory()->create(['slug' => 'team']);

    expect(Billing::plan('team')?->is($plan))->toBeTrue()
        ->and(Billing::driver('manual'))->toBeInstanceOf(ManualDriver::class);
});
