<?php

declare(strict_types=1);

use Laracaise\Billing\BillingContext;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Exceptions\FeatureNotAvailableException;
use Laracaise\Billing\Exceptions\NoActiveSubscriptionException;
use Laracaise\Billing\Exceptions\UsageLimitExceededException;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\PlanFeature;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Models\UsageRecord;
use Laracaise\Billing\Tests\Fixtures\BillableModel;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function owner(): BillableModel
{
    return BillableModel::create(['name' => 'Acme']);
}

function planWith(array $features = []): Plan
{
    $plan = Plan::factory()->create();

    foreach ($features as $slug => $config) {
        PlanFeature::factory()->create(array_merge([
            'plan_id' => $plan->id,
            'feature' => $slug,
        ], $config));
    }

    return $plan;
}

function subscribeOwner(BillableModel $owner, Plan $plan, array $attrs = []): Subscription
{
    return Subscription::factory()->forOwner($owner)->create(array_merge([
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ], $attrs));
}

// ---------------------------------------------------------------------------
// billing() helper on Billable trait
// ---------------------------------------------------------------------------

it('billing() on a billable model returns a BillingContext', function () {
    $owner = owner();

    expect($owner->billing())->toBeInstanceOf(BillingContext::class);
});

// ---------------------------------------------------------------------------
// subscription()
// ---------------------------------------------------------------------------

it('subscription() returns the active subscription', function () {
    $owner = owner();
    $plan = Plan::factory()->create();
    $sub = subscribeOwner($owner, $plan);

    expect($owner->billing()->subscription()?->id)->toBe($sub->id);
});

it('subscription() returns null when no subscription exists', function () {
    $owner = owner();

    expect($owner->billing()->subscription())->toBeNull();
});

it('subscription() returns a trialing subscription', function () {
    $owner = owner();
    $plan = Plan::factory()->create();
    subscribeOwner($owner, $plan, ['status' => SubscriptionStatus::Trialing]);

    expect($owner->billing()->subscription())->not->toBeNull();
});

it('subscription() returns a cancelled subscription within its grace period', function () {
    $owner = owner();
    $plan = Plan::factory()->create();
    subscribeOwner($owner, $plan, [
        'status' => SubscriptionStatus::Cancelled,
        'current_period_end' => now()->addDays(5),
    ]);

    expect($owner->billing()->subscription())->not->toBeNull();
});

it('subscription() returns null when cancelled subscription has expired', function () {
    $owner = owner();
    $plan = Plan::factory()->create();
    subscribeOwner($owner, $plan, [
        'status' => SubscriptionStatus::Cancelled,
        'current_period_end' => now()->subDay(),
    ]);

    expect($owner->billing()->subscription())->toBeNull();
});

// ---------------------------------------------------------------------------
// plan()
// ---------------------------------------------------------------------------

it('plan() returns the plan of the current subscription', function () {
    $owner = owner();
    $plan = planWith();
    subscribeOwner($owner, $plan);

    expect($owner->billing()->plan()?->id)->toBe($plan->id);
});

it('plan() returns null when not subscribed', function () {
    expect(owner()->billing()->plan())->toBeNull();
});

// ---------------------------------------------------------------------------
// isActive() / onTrial() / isSuspended()
// ---------------------------------------------------------------------------

it('isActive() is true for an active subscription', function () {
    $owner = owner();
    subscribeOwner($owner, Plan::factory()->create());

    expect($owner->billing()->isActive())->toBeTrue();
});

it('isActive() is false when cancelled', function () {
    $owner = owner();
    subscribeOwner($owner, Plan::factory()->create(), ['status' => SubscriptionStatus::Cancelled]);

    expect($owner->billing()->isActive())->toBeFalse();
});

it('onTrial() is true for a trialing subscription', function () {
    $owner = owner();
    subscribeOwner($owner, Plan::factory()->create(), ['status' => SubscriptionStatus::Trialing]);

    expect($owner->billing()->onTrial())->toBeTrue();
});

it('onTrial() is false for an active subscription', function () {
    $owner = owner();
    subscribeOwner($owner, Plan::factory()->create());

    expect($owner->billing()->onTrial())->toBeFalse();
});

it('isSuspended() is true for a past_due subscription', function () {
    $owner = owner();
    subscribeOwner($owner, Plan::factory()->create(), ['status' => SubscriptionStatus::PastDue]);

    expect($owner->billing()->isSuspended())->toBeTrue();
});

it('isSuspended() is false for an active subscription', function () {
    $owner = owner();
    subscribeOwner($owner, Plan::factory()->create());

    expect($owner->billing()->isSuspended())->toBeFalse();
});

// ---------------------------------------------------------------------------
// hasFeature()
// ---------------------------------------------------------------------------

it('hasFeature() returns false when not subscribed', function () {
    expect(owner()->billing()->hasFeature('reports'))->toBeFalse();
});

it('hasFeature() returns false for a feature not on the plan', function () {
    $owner = owner();
    subscribeOwner($owner, Plan::factory()->create());

    expect($owner->billing()->hasFeature('nonexistent'))->toBeFalse();
});

it('hasFeature() returns true for an enabled flag feature', function () {
    $owner = owner();
    $plan = planWith(['reports' => ['value' => 'true', 'resettable' => false]]);
    subscribeOwner($owner, $plan);

    expect($owner->billing()->hasFeature('reports'))->toBeTrue();
});

it('hasFeature() returns false for a disabled flag feature', function () {
    $owner = owner();
    $plan = planWith(['reports' => ['value' => 'false', 'resettable' => false]]);
    subscribeOwner($owner, $plan);

    expect($owner->billing()->hasFeature('reports'))->toBeFalse();
});

it('hasFeature() returns true for an unlimited numeric feature', function () {
    $owner = owner();
    $plan = planWith(['storage' => ['value' => null, 'resettable' => false]]);
    subscribeOwner($owner, $plan);

    expect($owner->billing()->hasFeature('storage'))->toBeTrue();
});

it('hasFeature() returns true for a numeric feature with a positive limit', function () {
    $owner = owner();
    $plan = planWith(['api_calls' => ['value' => '100', 'resettable' => true]]);
    subscribeOwner($owner, $plan);

    expect($owner->billing()->hasFeature('api_calls'))->toBeTrue();
});

it('hasFeature() returns false for a past_due (suspended) subscription', function () {
    $owner = owner();
    $plan = planWith(['reports' => ['value' => 'true', 'resettable' => false]]);
    subscribeOwner($owner, $plan, ['status' => SubscriptionStatus::PastDue]);

    expect($owner->billing()->hasFeature('reports'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// limit()
// ---------------------------------------------------------------------------

it('limit() throws NoActiveSubscriptionException when not subscribed', function () {
    owner()->billing()->limit('api_calls');
})->throws(NoActiveSubscriptionException::class);

it('limit() throws FeatureNotAvailableException for a feature not on the plan', function () {
    $owner = owner();
    subscribeOwner($owner, Plan::factory()->create());

    $owner->billing()->limit('nonexistent');
})->throws(FeatureNotAvailableException::class);

it('limit() throws FeatureNotAvailableException for a flag feature', function () {
    $owner = owner();
    $plan = planWith(['reports' => ['value' => 'true', 'resettable' => false]]);
    subscribeOwner($owner, $plan);

    $owner->billing()->limit('reports');
})->throws(FeatureNotAvailableException::class);

it('limit() returns null for an unlimited feature', function () {
    $owner = owner();
    $plan = planWith(['storage' => ['value' => null, 'resettable' => false]]);
    subscribeOwner($owner, $plan);

    expect($owner->billing()->limit('storage'))->toBeNull();
});

it('limit() returns the numeric limit', function () {
    $owner = owner();
    $plan = planWith(['api_calls' => ['value' => '500', 'resettable' => true]]);
    subscribeOwner($owner, $plan);

    expect($owner->billing()->limit('api_calls'))->toBe(500);
});

// ---------------------------------------------------------------------------
// canUse()
// ---------------------------------------------------------------------------

it('canUse() returns false when not subscribed', function () {
    expect(owner()->billing()->canUse('api_calls'))->toBeFalse();
});

it('canUse() returns true when under the limit', function () {
    $owner = owner();
    $plan = planWith(['api_calls' => ['value' => '100', 'resettable' => true]]);
    subscribeOwner($owner, $plan);

    expect($owner->billing()->canUse('api_calls', 50))->toBeTrue();
});

it('canUse() returns false when over the limit', function () {
    $owner = owner();
    $plan = planWith(['api_calls' => ['value' => '10', 'resettable' => true]]);
    $sub = subscribeOwner($owner, $plan);
    UsageRecord::factory()->create(['subscription_id' => $sub->id, 'feature' => 'api_calls', 'quantity' => 10, 'recorded_at' => now()]);

    expect($owner->billing()->canUse('api_calls', 1))->toBeFalse();
});

// ---------------------------------------------------------------------------
// consume()
// ---------------------------------------------------------------------------

it('consume() throws NoActiveSubscriptionException when not subscribed', function () {
    owner()->billing()->consume('api_calls');
})->throws(NoActiveSubscriptionException::class);

it('consume() records usage and returns a UsageRecord', function () {
    $owner = owner();
    $plan = planWith(['api_calls' => ['value' => '100', 'resettable' => true]]);
    subscribeOwner($owner, $plan);

    $record = $owner->billing()->consume('api_calls', 5);

    expect($record)->toBeInstanceOf(UsageRecord::class)
        ->and($record->quantity)->toBe(5)
        ->and(UsageRecord::count())->toBe(1);
});

it('consume() throws UsageLimitExceededException when the limit is already reached', function () {
    $owner = owner();
    $plan = planWith(['api_calls' => ['value' => '5', 'resettable' => true]]);
    $sub = subscribeOwner($owner, $plan);
    UsageRecord::factory()->create(['subscription_id' => $sub->id, 'feature' => 'api_calls', 'quantity' => 5, 'recorded_at' => now()]);

    $owner->billing()->consume('api_calls', 1);
})->throws(UsageLimitExceededException::class);

// ---------------------------------------------------------------------------
// remaining()
// ---------------------------------------------------------------------------

it('remaining() throws NoActiveSubscriptionException when not subscribed', function () {
    owner()->billing()->remaining('api_calls');
})->throws(NoActiveSubscriptionException::class);

it('remaining() returns remaining units', function () {
    $owner = owner();
    $plan = planWith(['api_calls' => ['value' => '100', 'resettable' => true]]);
    $sub = subscribeOwner($owner, $plan);
    UsageRecord::factory()->create(['subscription_id' => $sub->id, 'feature' => 'api_calls', 'quantity' => 40, 'recorded_at' => now()]);

    expect($owner->billing()->remaining('api_calls'))->toBe(60);
});

it('remaining() returns null for an unlimited feature', function () {
    $owner = owner();
    $plan = planWith(['storage' => ['value' => null, 'resettable' => false]]);
    subscribeOwner($owner, $plan);

    expect($owner->billing()->remaining('storage'))->toBeNull();
});

// ---------------------------------------------------------------------------
// resetUsage()
// ---------------------------------------------------------------------------

it('resetUsage() throws NoActiveSubscriptionException when not subscribed', function () {
    owner()->billing()->resetUsage('api_calls');
})->throws(NoActiveSubscriptionException::class);

it('resetUsage() zeroes usage for a specific feature', function () {
    $owner = owner();
    $plan = planWith(['api_calls' => ['value' => '100', 'resettable' => true]]);
    $sub = subscribeOwner($owner, $plan);
    UsageRecord::factory()->create(['subscription_id' => $sub->id, 'feature' => 'api_calls', 'quantity' => 70, 'recorded_at' => now()]);

    $owner->billing()->resetUsage('api_calls');

    expect($owner->billing()->remaining('api_calls'))->toBe(100);
});

it('resetUsage() zeroes all resettable features when called without a feature name', function () {
    $owner = owner();
    $plan = planWith([
        'api_calls' => ['value' => '100', 'resettable' => true],
        'exports' => ['value' => '20', 'resettable' => true],
        'storage' => ['value' => null, 'resettable' => false],
    ]);
    $sub = subscribeOwner($owner, $plan);
    UsageRecord::factory()->create(['subscription_id' => $sub->id, 'feature' => 'api_calls', 'quantity' => 80, 'recorded_at' => now()]);
    UsageRecord::factory()->create(['subscription_id' => $sub->id, 'feature' => 'exports', 'quantity' => 15, 'recorded_at' => now()]);

    $owner->billing()->resetUsage();

    expect($owner->billing()->remaining('api_calls'))->toBe(100)
        ->and($owner->billing()->remaining('exports'))->toBe(20);
});

// ---------------------------------------------------------------------------
// Named subscriptions
// ---------------------------------------------------------------------------

it('named subscriptions are isolated from each other', function () {
    $owner = owner();
    $planA = planWith(['feature_a' => ['value' => 'true', 'resettable' => false]]);
    $planAddon = planWith(['feature_b' => ['value' => 'true', 'resettable' => false]]);

    subscribeOwner($owner, $planA, ['name' => 'default']);
    subscribeOwner($owner, $planAddon, ['name' => 'addon']);

    // default slot has feature_a but not feature_b
    expect($owner->billing()->hasFeature('feature_a'))->toBeTrue()
        ->and($owner->billing()->hasFeature('feature_b'))->toBeFalse();

    // addon slot has feature_b but not feature_a
    expect($owner->billing()->hasFeature('feature_b', 'addon'))->toBeTrue()
        ->and($owner->billing()->hasFeature('feature_a', 'addon'))->toBeFalse();
});
