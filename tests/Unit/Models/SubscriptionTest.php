<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Tests\Fixtures\BillableModel;

it('creates the billing_subscriptions table', function () {
    expect(Schema::hasTable('billing_subscriptions'))->toBeTrue();
});

it('has the expected columns', function () {
    foreach (['subscriptionable_type', 'subscriptionable_id', 'plan_id', 'status', 'provider', 'provider_id'] as $column) {
        expect(Schema::hasColumn('billing_subscriptions', $column))->toBeTrue();
    }
});

it('can create a subscription via the factory', function () {
    $sub = Subscription::factory()->create();

    expect($sub->id)->toBeString()
        ->and($sub->status)->toBe(SubscriptionStatus::Active);
});

it('casts status to SubscriptionStatus enum', function () {
    $sub = Subscription::factory()->active()->create();

    expect($sub->status)->toBe(SubscriptionStatus::Active);
});

it('belongs to a plan', function () {
    $plan = Plan::factory()->create();
    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);

    expect($sub->plan->id)->toBe($plan->id);
});

it('morphs to the subscriptionable owner', function () {
    $owner = BillableModel::create(['name' => 'Acme']);
    $sub = Subscription::factory()->forOwner($owner)->create();

    expect($sub->subscriptionable->id)->toBe($owner->id);
});

it('isActive returns true only when status is active', function () {
    expect(Subscription::factory()->active()->create()->isActive())->toBeTrue()
        ->and(Subscription::factory()->cancelled()->create()->isActive())->toBeFalse();
});

it('isTrialing returns true only when status is trialing', function () {
    expect(Subscription::factory()->trialing()->create()->isTrialing())->toBeTrue()
        ->and(Subscription::factory()->active()->create()->isTrialing())->toBeFalse();
});

it('isCancelled returns true only when status is cancelled', function () {
    expect(Subscription::factory()->cancelled()->create()->isCancelled())->toBeTrue()
        ->and(Subscription::factory()->active()->create()->isCancelled())->toBeFalse();
});

it('isPastDue returns true only when status is past_due', function () {
    expect(Subscription::factory()->pastDue()->create()->isPastDue())->toBeTrue()
        ->and(Subscription::factory()->active()->create()->isPastDue())->toBeFalse();
});

it('onGracePeriod is true when cancelled with a future period end', function () {
    $sub = Subscription::factory()->cancelled()->create([
        'current_period_end' => now()->addDays(10),
    ]);

    expect($sub->onGracePeriod())->toBeTrue();
});

it('onGracePeriod is false when cancelled and period has expired', function () {
    $sub = Subscription::factory()->cancelled()->create([
        'current_period_end' => now()->subDay(),
    ]);

    expect($sub->onGracePeriod())->toBeFalse();
});

it('active scope returns only active subscriptions', function () {
    Subscription::factory()->active()->create();
    Subscription::factory()->cancelled()->create();
    Subscription::factory()->trialing()->create();

    expect(Subscription::active()->count())->toBe(1);
});

it('activeOrTrialing scope includes active and trialing', function () {
    Subscription::factory()->active()->create();
    Subscription::factory()->trialing()->create();
    Subscription::factory()->cancelled()->create();

    expect(Subscription::activeOrTrialing()->count())->toBe(2);
});

it('forOwner scope returns only that owner\'s subscriptions', function () {
    $ownerA = BillableModel::create(['name' => 'A']);
    $ownerB = BillableModel::create(['name' => 'B']);

    Subscription::factory()->forOwner($ownerA)->create();
    Subscription::factory()->forOwner($ownerA)->create();
    Subscription::factory()->forOwner($ownerB)->create();

    expect(Subscription::forOwner($ownerA)->count())->toBe(2)
        ->and(Subscription::forOwner($ownerB)->count())->toBe(1);
});

it('withName scope filters by subscription name', function () {
    Subscription::factory()->create(['name' => 'default']);
    Subscription::factory()->create(['name' => 'addon']);

    expect(Subscription::withName('default')->count())->toBe(1)
        ->and(Subscription::withName('addon')->count())->toBe(1);
});

it('expiringBefore scope returns subscriptions expiring before a date', function () {
    Subscription::factory()->create(['current_period_end' => now()->addDays(3)]);
    Subscription::factory()->create(['current_period_end' => now()->addDays(30)]);

    expect(Subscription::expiringBefore(now()->addDays(7))->count())->toBe(1);
});

it('forProvider scope filters by provider name', function () {
    Subscription::factory()->withProvider('paystack')->create();
    Subscription::factory()->withProvider('manual')->create();

    expect(Subscription::forProvider('paystack')->count())->toBe(1);
});
