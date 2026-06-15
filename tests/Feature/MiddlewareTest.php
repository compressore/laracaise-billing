<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\PlanFeature;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Tests\Fixtures\BillableModel;

function billingMiddlewareOwner(array $subscriptionAttributes = [], array $features = []): BillableModel
{
    $owner = BillableModel::create(['name' => 'Middleware Owner']);
    $plan = Plan::factory()->create();

    foreach ($features as $feature => $value) {
        PlanFeature::factory()->create([
            'plan_id' => $plan->id,
            'feature' => $feature,
            'value' => is_bool($value) ? ($value ? 'true' : 'false') : $value,
            'resettable' => false,
        ]);
    }

    Subscription::factory()->forOwner($owner)->create(array_merge([
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ], $subscriptionAttributes));

    return $owner;
}

beforeEach(function () {
    Route::get('/middleware-active/{billable}', fn (BillableModel $billable) => 'ok')
        ->middleware([
            SubstituteBindings::class,
            'billing.active:default,billable',
        ]);

    Route::get('/middleware-feature/{billable}', fn (BillableModel $billable) => 'ok')
        ->middleware([
            SubstituteBindings::class,
            'billing.feature:reports,default,billable',
        ]);

    Route::get('/middleware-suspended/{billable}', fn (BillableModel $billable) => 'ok')
        ->middleware([
            SubstituteBindings::class,
            'billing.not_suspended:default,billable',
        ]);
});

it('allows route access when the billable has an active subscription', function () {
    $owner = billingMiddlewareOwner();

    $this->get("/middleware-active/{$owner->id}")
        ->assertOk()
        ->assertSee('ok');
});

it('rejects route access when the billable has no accessible subscription', function () {
    $owner = billingMiddlewareOwner([
        'status' => SubscriptionStatus::Expired,
        'current_period_end' => now()->subDay(),
    ]);

    $this->get("/middleware-active/{$owner->id}")
        ->assertStatus(402);
});

it('rejects active middleware route access when the subscription is suspended', function () {
    $owner = billingMiddlewareOwner(['status' => SubscriptionStatus::PastDue]);

    $this->get("/middleware-active/{$owner->id}")
        ->assertStatus(402);
});

it('allows route access when the required feature is available', function () {
    $owner = billingMiddlewareOwner(features: ['reports' => true]);

    $this->get("/middleware-feature/{$owner->id}")
        ->assertOk()
        ->assertSee('ok');
});

it('rejects route access when the required feature is unavailable', function () {
    $owner = billingMiddlewareOwner(features: ['reports' => false]);

    $this->get("/middleware-feature/{$owner->id}")
        ->assertStatus(402);
});

it('rejects route access when the subscription is suspended', function () {
    $owner = billingMiddlewareOwner(['status' => SubscriptionStatus::PastDue]);

    $this->get("/middleware-suspended/{$owner->id}")
        ->assertForbidden();
});
