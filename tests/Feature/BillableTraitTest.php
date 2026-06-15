<?php

declare(strict_types=1);

use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Models\UsageRecord;
use Laracaise\Billing\Tests\Fixtures\BillableModel;

it('provides a subscriptions morph-many relationship', function () {
    $owner = BillableModel::create(['name' => 'Acme']);

    Subscription::factory()->forOwner($owner)->create();
    Subscription::factory()->forOwner($owner)->create();

    expect($owner->subscriptions)->toHaveCount(2);
});

it('does not return another model\'s subscriptions', function () {
    $a = BillableModel::create(['name' => 'A']);
    $b = BillableModel::create(['name' => 'B']);

    Subscription::factory()->forOwner($a)->create();
    Subscription::factory()->forOwner($b)->create();
    Subscription::factory()->forOwner($b)->create();

    expect($a->subscriptions)->toHaveCount(1)
        ->and($b->subscriptions)->toHaveCount(2);
});

it('scopes subscription morph correctly by model class', function () {
    $owner = BillableModel::create(['name' => 'Test']);

    Subscription::factory()->forOwner($owner)->create();
    $sub = $owner->subscriptions()->first();

    expect($sub->subscriptionable_type)->toBe(BillableModel::class);
});

it('provides a payments morph-many relationship', function () {
    $owner = BillableModel::create(['name' => 'Acme']);
    $sub = Subscription::factory()->forOwner($owner)->create();

    Payment::factory()->forOwner($owner)->create(['subscription_id' => $sub->id]);
    Payment::factory()->forOwner($owner)->create(['subscription_id' => null]);

    expect($owner->payments)->toHaveCount(2);
});

it('does not return another model\'s payments', function () {
    $a = BillableModel::create(['name' => 'A']);
    $b = BillableModel::create(['name' => 'B']);

    Payment::factory()->forOwner($a)->create(['subscription_id' => null]);
    Payment::factory()->forOwner($b)->create(['subscription_id' => null]);

    expect($a->payments)->toHaveCount(1)
        ->and($b->payments)->toHaveCount(1);
});

it('can eager-load subscriptions with the plan', function () {
    $owner = BillableModel::create(['name' => 'Acme']);

    Subscription::factory()->forOwner($owner)->create();

    $loaded = BillableModel::with('subscriptions.plan')->find($owner->id);

    expect($loaded->subscriptions->first()->plan)->not->toBeNull();
});

it('can eager-load subscriptions with usage records', function () {
    $owner = BillableModel::create(['name' => 'Acme']);
    $sub = Subscription::factory()->forOwner($owner)->create();

    UsageRecord::factory()->create([
        'subscription_id' => $sub->id,
        'feature' => 'api_calls',
        'quantity' => 10,
    ]);

    $loaded = BillableModel::with('subscriptions.usageRecords')->find($owner->id);

    expect($loaded->subscriptions->first()->usageRecords)->toHaveCount(1);
});

it('resolves the correct subscriptionable instance via morphTo', function () {
    $owner = BillableModel::create(['name' => 'Resolves']);
    $sub = Subscription::factory()->forOwner($owner)->create();

    $resolved = Subscription::find($sub->id)->subscriptionable;

    expect($resolved)->toBeInstanceOf(BillableModel::class)
        ->and($resolved->id)->toBe($owner->id);
});

it('different model types can both be billable using the same tables', function () {
    // Two separate BillableModel instances represent two independent "tenants"
    $teamA = BillableModel::create(['name' => 'Team A']);
    $teamB = BillableModel::create(['name' => 'Team B']);

    Subscription::factory()->forOwner($teamA)->count(3)->create();
    Subscription::factory()->forOwner($teamB)->count(1)->create();

    expect(Subscription::forOwner($teamA)->count())->toBe(3)
        ->and(Subscription::forOwner($teamB)->count())->toBe(1)
        ->and(Subscription::count())->toBe(4);
});
