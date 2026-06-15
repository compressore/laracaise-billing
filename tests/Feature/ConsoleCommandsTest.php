<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Laracaise\Billing\Enums\BillingInterval;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Events\SubscriptionExpired;
use Laracaise\Billing\Events\SubscriptionRenewed;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\PlanFeature;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Models\UsageRecord;
use Laracaise\Billing\Services\UsageService;

it('syncs configured plans and features', function () {
    config()->set('laracaise-billing.plans', [
        'pro' => [
            'name' => 'Pro',
            'amount' => 12_500,
            'currency' => 'ZAR',
            'interval' => 'monthly',
            'features' => [
                'reports' => ['value' => true, 'resettable' => false],
                'api_calls' => ['value' => 1000, 'resettable' => true],
                'storage' => null,
            ],
        ],
    ]);

    $this->artisan('billing:sync')
        ->assertSuccessful();

    $plan = Plan::query()->where('slug', 'pro')->first();

    expect($plan)->not->toBeNull()
        ->and($plan->amount)->toBe(12_500)
        ->and($plan->features()->count())->toBe(3)
        ->and(PlanFeature::query()->where('feature', 'reports')->first()?->value)->toBe('true')
        ->and(PlanFeature::query()->where('feature', 'storage')->first()?->value)->toBeNull();
});

it('resets usage for a subscription from the command line', function () {
    $plan = Plan::factory()->create();
    PlanFeature::factory()->create(['plan_id' => $plan->id, 'feature' => 'api_calls', 'value' => '100']);
    $subscription = Subscription::factory()->create(['plan_id' => $plan->id]);
    UsageRecord::factory()->create(['subscription_id' => $subscription->id, 'feature' => 'api_calls', 'quantity' => 70]);

    $this->artisan('billing:reset-usage', [
        'subscription' => $subscription->id,
        '--feature' => 'api_calls',
    ])->assertSuccessful();

    expect(app(UsageService::class)->getUsageInPeriod($subscription->refresh(), 'api_calls'))->toBe(0);
});

it('expires cancelled subscriptions after their grace period', function () {
    $subscription = Subscription::factory()->cancelled()->create([
        'current_period_end' => now()->subDay(),
    ]);

    Event::fake([SubscriptionExpired::class]);

    $this->artisan('billing:expire-subscriptions')
        ->assertSuccessful();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);
    Event::assertDispatched(SubscriptionExpired::class);
});

it('processes due recurring renewals and resets usage', function () {
    $plan = Plan::factory()->create([
        'interval' => BillingInterval::Monthly,
        'interval_count' => 1,
    ]);
    PlanFeature::factory()->create(['plan_id' => $plan->id, 'feature' => 'api_calls', 'value' => '100']);
    $subscription = Subscription::factory()->create([
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'current_period_start' => now()->subMonths(2),
        'current_period_end' => now()->subDay(),
    ]);
    UsageRecord::factory()->create([
        'subscription_id' => $subscription->id,
        'feature' => 'api_calls',
        'quantity' => 70,
        'recorded_at' => $subscription->current_period_end?->copy()->subHour(),
    ]);

    Event::fake([SubscriptionRenewed::class]);

    $this->artisan('billing:process-renewals')
        ->assertSuccessful();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_end?->greaterThan(now()->subDay()))->toBeTrue()
        ->and(app(UsageService::class)->getUsageInPeriod($subscription, 'api_calls'))->toBe(0);

    Event::assertDispatched(SubscriptionRenewed::class);
});
