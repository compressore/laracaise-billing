<?php

declare(strict_types=1);

use Laracaise\Billing\Events\ManualPaymentRecorded;
use Laracaise\Billing\Events\PaymentFailed;
use Laracaise\Billing\Events\PaymentSucceeded;
use Laracaise\Billing\Events\PlanChanged;
use Laracaise\Billing\Events\SubscriptionActivated;
use Laracaise\Billing\Events\SubscriptionCancelled;
use Laracaise\Billing\Events\SubscriptionCreated;
use Laracaise\Billing\Events\SubscriptionExpired;
use Laracaise\Billing\Events\SubscriptionExtended;
use Laracaise\Billing\Events\SubscriptionRenewed;
use Laracaise\Billing\Events\SubscriptionResumed;
use Laracaise\Billing\Events\SubscriptionSuspended;
use Laracaise\Billing\Events\TrialEnded;
use Laracaise\Billing\Events\TrialStarted;
use Laracaise\Billing\Events\UsageLimitReached;
use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\Subscription;

it('subscription events expose the subscription payload', function (string $eventClass) {
    $subscription = Subscription::factory()->create();
    $event = new $eventClass($subscription);

    expect($event->subscription)->toBe($subscription);
})->with([
    SubscriptionActivated::class,
    SubscriptionCancelled::class,
    SubscriptionCreated::class,
    SubscriptionExpired::class,
    SubscriptionExtended::class,
    SubscriptionRenewed::class,
    SubscriptionResumed::class,
    SubscriptionSuspended::class,
    TrialEnded::class,
    TrialStarted::class,
]);

it('manual payment event exposes the payment and subscription payloads', function () {
    $subscription = Subscription::factory()->create();
    $payment = Payment::factory()->create(['subscription_id' => $subscription->id]);

    $event = new ManualPaymentRecorded($payment, $subscription);

    expect($event->payment)->toBe($payment)
        ->and($event->subscription)->toBe($subscription);
});

it('payment events expose the payment payload', function (string $eventClass) {
    $payment = Payment::factory()->create();
    $event = new $eventClass($payment);

    expect($event->payment)->toBe($payment);
})->with([
    PaymentFailed::class,
    PaymentSucceeded::class,
]);

it('plan changed event exposes subscription and plan payloads', function () {
    $subscription = Subscription::factory()->create();
    $previousPlan = Plan::factory()->create();
    $newPlan = Plan::factory()->create();

    $event = new PlanChanged($subscription, $previousPlan, $newPlan);

    expect($event->subscription)->toBe($subscription)
        ->and($event->previousPlan)->toBe($previousPlan)
        ->and($event->newPlan)->toBe($newPlan);
});

it('usage limit event exposes limit metadata', function () {
    $subscription = Subscription::factory()->create();

    $event = new UsageLimitReached($subscription, 'api_calls', 100, 90, 20);

    expect($event->subscription)->toBe($subscription)
        ->and($event->feature)->toBe('api_calls')
        ->and($event->limit)->toBe(100)
        ->and($event->used)->toBe(90)
        ->and($event->requested)->toBe(20);
});
