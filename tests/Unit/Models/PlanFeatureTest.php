<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\PlanFeature;

it('creates the billing_plan_features table', function () {
    expect(Schema::hasTable('billing_plan_features'))->toBeTrue();
});

it('can create a plan feature using the factory', function () {
    $feature = PlanFeature::factory()->create();

    expect($feature->id)->toBeString()
        ->and($feature->feature)->toBeString();
});

it('belongs to a plan', function () {
    $plan = Plan::factory()->create();
    $feature = PlanFeature::factory()->create(['plan_id' => $plan->id]);

    expect($feature->plan->id)->toBe($plan->id);
});

it('correctly identifies an unlimited feature', function () {
    $unlimited = PlanFeature::factory()->unlimited()->create();
    $limited = PlanFeature::factory()->limit(500)->create();

    expect($unlimited->isUnlimited())->toBeTrue()
        ->and($limited->isUnlimited())->toBeFalse();
});

it('correctly identifies a flag feature', function () {
    $flag = PlanFeature::factory()->flag()->create();
    $limit = PlanFeature::factory()->limit(100)->create();

    expect($flag->isFlag())->toBeTrue()
        ->and($limit->isFlag())->toBeFalse();
});

it('returns the correct flag value', function () {
    $enabled = PlanFeature::factory()->flag(true)->create();
    $disabled = PlanFeature::factory()->flag(false)->create();

    expect($enabled->flagValue())->toBeTrue()
        ->and($disabled->flagValue())->toBeFalse();
});

it('returns the correct numeric limit value', function () {
    $feature = PlanFeature::factory()->limit(1_000)->create();

    expect($feature->limitValue())->toBe(1_000);
});

it('returns null for limitValue on unlimited features', function () {
    expect(PlanFeature::factory()->unlimited()->create()->limitValue())->toBeNull();
});

it('forFeature scope filters by feature key', function () {
    PlanFeature::factory()->create(['feature' => 'api_calls']);
    PlanFeature::factory()->create(['feature' => 'seats']);

    expect(PlanFeature::forFeature('api_calls')->count())->toBe(1);
});

it('resettable scope excludes non-resettable features', function () {
    PlanFeature::factory()->create(['resettable' => true]);
    PlanFeature::factory()->nonResettable()->create();

    expect(PlanFeature::resettable()->count())->toBe(1);
});

it('enforces unique feature key per plan', function () {
    $plan = Plan::factory()->create();

    PlanFeature::factory()->create(['plan_id' => $plan->id, 'feature' => 'seats']);

    expect(fn () => PlanFeature::factory()->create([
        'plan_id' => $plan->id,
        'feature' => 'seats',
    ]))->toThrow(QueryException::class);
});
