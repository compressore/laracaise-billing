<?php

declare(strict_types=1);

use Laracaise\Billing\Events\ManualPaymentRecorded;
use Laracaise\Billing\Events\PaymentFailed;
use Laracaise\Billing\Events\PaymentSucceeded;
use Laracaise\Billing\Events\SubscriptionActivated;
use Laracaise\Billing\Events\SubscriptionCancelled;
use Laracaise\Billing\Events\SubscriptionCreated;
use Laracaise\Billing\Events\SubscriptionExpired;
use Laracaise\Billing\Events\SubscriptionExtended;
use Laracaise\Billing\Events\SubscriptionRenewed;
use Laracaise\Billing\Events\SubscriptionResumed;
use Laracaise\Billing\Events\SubscriptionSuspended;
use Laracaise\Billing\Models\Payment;
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
