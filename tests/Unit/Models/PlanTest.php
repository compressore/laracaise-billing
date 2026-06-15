<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Laracaise\Billing\Enums\BillingInterval;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\PlanFeature;

it('creates the billing_plans table', function () {
    expect(Schema::hasTable('billing_plans'))->toBeTrue();
});

it('has the expected columns', function () {
    foreach (['id', 'name', 'slug', 'amount', 'currency', 'interval', 'is_active', 'metadata'] as $column) {
        expect(Schema::hasColumn('billing_plans', $column))->toBeTrue();
    }
});

it('can create a plan using the factory', function () {
    $plan = Plan::factory()->create();

    expect($plan->id)->toBeString()
        ->and($plan->is_active)->toBeTrue()
        ->and($plan->amount)->toBeInt();
});

it('casts interval to BillingInterval enum', function () {
    $plan = Plan::factory()->monthly()->create();

    expect($plan->interval)->toBe(BillingInterval::Monthly);
});

it('casts metadata to array', function () {
    $plan = Plan::factory()->create(['metadata' => ['key' => 'value']]);

    expect($plan->metadata)->toBe(['key' => 'value']);
});

it('has a features relationship', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create(['plan_id' => $plan->id]);

    expect($plan->features()->count())->toBe(1);
});

it('correctly reports a free plan', function () {
    $free = Plan::factory()->free()->create();
    $paid = Plan::factory()->create(['amount' => 1000]);

    expect($free->isFree())->toBeTrue()
        ->and($paid->isFree())->toBeFalse();
});

it('correctly reports a plan with a trial', function () {
    $trial = Plan::factory()->withTrial(14)->create();
    $noTrial = Plan::factory()->create();

    expect($trial->hasTrial())->toBeTrue()
        ->and($noTrial->hasTrial())->toBeFalse();
});

it('active scope excludes inactive plans', function () {
    Plan::factory()->create(['is_active' => true]);
    Plan::factory()->inactive()->create();

    expect(Plan::active()->count())->toBe(1);
});

it('ordered scope sorts by sort_order then name', function () {
    Plan::factory()->create(['name' => 'Zebra', 'sort_order' => 2]);
    Plan::factory()->create(['name' => 'Alpha', 'sort_order' => 1]);

    $plans = Plan::ordered()->get();

    expect($plans->first()->sort_order)->toBe(1);
});

it('withInterval scope filters by billing interval', function () {
    Plan::factory()->monthly()->create();
    Plan::factory()->yearly()->create();

    expect(Plan::withInterval(BillingInterval::Monthly)->count())->toBe(1)
        ->and(Plan::withInterval(BillingInterval::Yearly)->count())->toBe(1);
});
