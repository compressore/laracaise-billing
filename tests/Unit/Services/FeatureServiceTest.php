<?php

declare(strict_types=1);

use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\PlanFeature;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Models\SubscriptionOverride;
use Laracaise\Billing\Services\FeatureService;
use Laracaise\Billing\ValueObjects\FeatureValue;

beforeEach(function () {
    $this->service = app(FeatureService::class);
});

// ---------------------------------------------------------------------------
// resolve()
// ---------------------------------------------------------------------------

it('returns null when the subscription has no plan', function () {
    $sub = new Subscription;

    expect($this->service->resolve($sub, 'reports'))->toBeNull();
});

it('returns null when the feature does not exist on the plan', function () {
    $plan = Plan::factory()->create();
    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);

    expect($this->service->resolve($sub, 'nonexistent'))->toBeNull();
});

it('returns a FeatureValue from the plan when no override exists', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create([
        'plan_id' => $plan->id,
        'feature' => 'api_calls',
        'value' => '1000',
        'resettable' => true,
    ]);
    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);

    $resolved = $this->service->resolve($sub, 'api_calls');

    expect($resolved)->toBeInstanceOf(FeatureValue::class)
        ->and($resolved->feature)->toBe('api_calls')
        ->and($resolved->value)->toBe('1000')
        ->and($resolved->resettable)->toBeTrue()
        ->and($resolved->source)->toBe('plan');
});

it('returns a FeatureValue with flag value true', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create([
        'plan_id' => $plan->id,
        'feature' => 'custom_domain',
        'value' => 'true',
        'resettable' => false,
    ]);
    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);
    $resolved = $this->service->resolve($sub, 'custom_domain');

    expect($resolved->isFlag())->toBeTrue()
        ->and($resolved->flagValue())->toBeTrue();
});

it('returns a FeatureValue with flag value false', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create([
        'plan_id' => $plan->id,
        'feature' => 'white_label',
        'value' => 'false',
        'resettable' => false,
    ]);
    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);
    $resolved = $this->service->resolve($sub, 'white_label');

    expect($resolved->isFlag())->toBeTrue()
        ->and($resolved->flagValue())->toBeFalse();
});

it('returns a FeatureValue with null value for an unlimited feature', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create([
        'plan_id' => $plan->id,
        'feature' => 'storage',
        'value' => null,
    ]);
    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);
    $resolved = $this->service->resolve($sub, 'storage');

    expect($resolved->isUnlimited())->toBeTrue()
        ->and($resolved->limit())->toBeNull();
});

it('uses an active override value instead of the plan value', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create([
        'plan_id' => $plan->id,
        'feature' => 'api_calls',
        'value' => '1000',
        'resettable' => true,
    ]);
    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);
    SubscriptionOverride::factory()->create([
        'subscription_id' => $sub->id,
        'feature' => 'api_calls',
        'value' => '5000',
        'expires_at' => now()->addMonth(),
    ]);

    $resolved = $this->service->resolve($sub, 'api_calls');

    expect($resolved->value)->toBe('5000')
        ->and($resolved->source)->toBe('override')
        ->and($resolved->resettable)->toBeTrue();
});

it('ignores an expired override and falls back to the plan value', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create([
        'plan_id' => $plan->id,
        'feature' => 'api_calls',
        'value' => '1000',
    ]);
    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);
    SubscriptionOverride::factory()->create([
        'subscription_id' => $sub->id,
        'feature' => 'api_calls',
        'value' => '5000',
        'expires_at' => now()->subDay(),
    ]);

    $resolved = $this->service->resolve($sub, 'api_calls');

    expect($resolved->value)->toBe('1000')
        ->and($resolved->source)->toBe('plan');
});

it('always takes resettable from the plan, even when an override is active', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create([
        'plan_id' => $plan->id,
        'feature' => 'api_calls',
        'value' => '1000',
        'resettable' => true,
    ]);
    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);
    SubscriptionOverride::factory()->create([
        'subscription_id' => $sub->id,
        'feature' => 'api_calls',
        'value' => '9999',
        'expires_at' => now()->addMonth(),
    ]);

    $resolved = $this->service->resolve($sub, 'api_calls');

    expect($resolved->resettable)->toBeTrue();
});

// ---------------------------------------------------------------------------
// allResettable()
// ---------------------------------------------------------------------------

it('returns an empty array when the subscription has no plan', function () {
    $sub = new Subscription;

    expect($this->service->allResettable($sub))->toBeEmpty();
});

it('returns only resettable features', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create(['plan_id' => $plan->id, 'feature' => 'api_calls', 'value' => '100', 'resettable' => true]);
    PlanFeature::factory()->create(['plan_id' => $plan->id, 'feature' => 'storage', 'value' => null, 'resettable' => false]);
    PlanFeature::factory()->create(['plan_id' => $plan->id, 'feature' => 'exports', 'value' => '10', 'resettable' => true]);

    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);
    $result = $this->service->allResettable($sub);

    expect($result)->toHaveCount(2);
    $features = array_map(fn ($fv) => $fv->feature, $result);
    expect($features)->toContain('api_calls')->toContain('exports');
});

it('returns an empty array when no features are resettable', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create(['plan_id' => $plan->id, 'feature' => 'reports', 'value' => 'true', 'resettable' => false]);

    $sub = Subscription::factory()->create(['plan_id' => $plan->id]);

    expect($this->service->allResettable($sub))->toBeEmpty();
});
