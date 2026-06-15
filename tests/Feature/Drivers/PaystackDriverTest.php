<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laracaise\Billing\Drivers\PaystackDriver;
use Laracaise\Billing\Enums\BillingInterval;
use Laracaise\Billing\Enums\PaymentStatus;
use Laracaise\Billing\Enums\PaymentType;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Events\PaymentFailed;
use Laracaise\Billing\Events\PaymentSucceeded;
use Laracaise\Billing\Events\SubscriptionActivated;
use Laracaise\Billing\Events\SubscriptionRenewed;
use Laracaise\Billing\Events\SubscriptionSuspended;
use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Tests\Fixtures\BillableModel;
use Laracaise\Billing\ValueObjects\PendingTransaction;

beforeEach(function () {
    config()->set('laracaise-billing.drivers.paystack.secret_key', 'sk_test_driver');
    config()->set('laracaise-billing.drivers.paystack.webhook_secret', 'whsec_test_driver');
    config()->set('laracaise-billing.drivers.paystack.base_url', 'https://api.paystack.co');
});

function paystackDriver(): PaystackDriver
{
    return new PaystackDriver(config('laracaise-billing.drivers.paystack'));
}

function paystackOwner(): BillableModel
{
    return BillableModel::create(['name' => 'Acme']);
}

function paystackMonthlyPlan(): Plan
{
    return Plan::factory()->create([
        'interval' => BillingInterval::Monthly,
        'interval_count' => 1,
        'currency' => 'ZAR',
    ]);
}

function paystackSubscription(SubscriptionStatus $status = SubscriptionStatus::Pending): Subscription
{
    return Subscription::factory()->forOwner(paystackOwner())->create([
        'plan_id' => paystackMonthlyPlan()->id,
        'provider' => 'paystack',
        'status' => $status,
        'current_period_start' => $status === SubscriptionStatus::Pending ? null : now()->startOfMonth(),
        'current_period_end' => $status === SubscriptionStatus::Pending ? null : now()->endOfMonth(),
    ]);
}

function paystackPayment(Subscription $subscription, array $attributes = []): Payment
{
    return Payment::factory()->create(array_merge([
        'subscriptionable_type' => $subscription->subscriptionable_type,
        'subscriptionable_id' => $subscription->subscriptionable_id,
        'subscription_id' => $subscription->id,
        'amount' => 12_500,
        'currency' => 'ZAR',
        'status' => PaymentStatus::Pending,
        'type' => PaymentType::Charge,
        'provider' => null,
        'provider_reference' => null,
        'paid_at' => null,
    ], $attributes));
}

function paystackVerifyPayload(string $reference, string $status = 'success', array $metadata = []): array
{
    return [
        'status' => true,
        'message' => 'Verification successful',
        'data' => [
            'reference' => $reference,
            'status' => $status,
            'amount' => 12_500,
            'currency' => 'ZAR',
            'metadata' => $metadata,
        ],
    ];
}

it('initializes a Paystack checkout transaction', function () {
    $subscription = paystackSubscription();
    $payment = paystackPayment($subscription);

    Http::fake([
        'https://api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.test/abc',
                'access_code' => 'ACCESS_abc',
                'reference' => 'PSK_ref_001',
            ],
        ]),
    ]);

    $pending = paystackDriver()->initializeTransaction($payment, [
        'email' => 'customer@example.test',
        'callback_url' => 'https://app.test/billing/return',
        'metadata' => ['source' => 'checkout'],
    ]);

    expect($pending)->toBeInstanceOf(PendingTransaction::class)
        ->and($pending->reference)->toBe('PSK_ref_001')
        ->and($pending->checkoutUrl)->toBe('https://checkout.paystack.test/abc')
        ->and($pending->meta['access_code'])->toBe('ACCESS_abc')
        ->and($payment->refresh()->provider)->toBe('paystack')
        ->and($payment->provider_reference)->toBe('PSK_ref_001')
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->metadata['payment_id'])->toBe($payment->id)
        ->and($payment->metadata['subscription_id'])->toBe($subscription->id)
        ->and($payment->metadata['source'])->toBe('checkout');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk_test_driver')
        && $request->url() === 'https://api.paystack.co/transaction/initialize'
        && $request['email'] === 'customer@example.test'
        && $request['amount'] === 12_500
    );
});

it('verifies a successful transaction and activates a pending subscription', function () {
    Event::fake();

    $subscription = paystackSubscription();
    $payment = paystackPayment($subscription, [
        'provider' => 'paystack',
        'provider_reference' => 'PSK_success_001',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/PSK_success_001' => Http::response(
            paystackVerifyPayload('PSK_success_001', metadata: [
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
            ])
        ),
    ]);

    $verified = paystackDriver()->verifyTransaction('PSK_success_001');

    expect($verified->id)->toBe($payment->id)
        ->and($verified->status)->toBe(PaymentStatus::Succeeded)
        ->and($verified->paid_at)->not->toBeNull()
        ->and($verified->provider_response['data']['reference'])->toBe('PSK_success_001')
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_start)->not->toBeNull()
        ->and($subscription->current_period_end)->not->toBeNull();

    Event::assertDispatched(PaymentSucceeded::class, fn ($event) => $event->payment->id === $payment->id);
    Event::assertDispatched(SubscriptionActivated::class, fn ($event) => $event->subscription->id === $subscription->id);
});

it('verifies a successful renewal payment and advances the subscription period', function () {
    Event::fake();

    $subscription = paystackSubscription(SubscriptionStatus::Active);
    $oldEnd = $subscription->current_period_end?->copy();
    $payment = paystackPayment($subscription, [
        'provider' => 'paystack',
        'provider_reference' => 'PSK_renew_001',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/PSK_renew_001' => Http::response(
            paystackVerifyPayload('PSK_renew_001', metadata: [
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
            ])
        ),
    ]);

    paystackDriver()->verifyTransaction('PSK_renew_001');

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_start?->toDateString())->toBe($oldEnd?->toDateString())
        ->and($subscription->current_period_end?->gt($oldEnd))->toBeTrue();

    Event::assertDispatched(SubscriptionRenewed::class, fn ($event) => $event->subscription->id === $subscription->id);
});

it('verifies a failed transaction, marks payment failed, and suspends active subscription', function () {
    Event::fake();

    $subscription = paystackSubscription(SubscriptionStatus::Active);
    $payment = paystackPayment($subscription, [
        'provider' => 'paystack',
        'provider_reference' => 'PSK_failed_001',
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/PSK_failed_001' => Http::response(
            paystackVerifyPayload('PSK_failed_001', 'failed', [
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
            ])
        ),
    ]);

    $verified = paystackDriver()->verifyTransaction('PSK_failed_001');

    expect($verified->status)->toBe(PaymentStatus::Failed)
        ->and($verified->paid_at)->toBeNull()
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue);

    Event::assertDispatched(PaymentFailed::class, fn ($event) => $event->payment->id === $payment->id);
    Event::assertDispatched(SubscriptionSuspended::class, fn ($event) => $event->subscription->id === $subscription->id);
});

it('does not renew a subscription twice for an already succeeded payment', function () {
    Event::fake();

    $subscription = paystackSubscription(SubscriptionStatus::Active);
    $oldEnd = $subscription->current_period_end?->copy();
    $payment = paystackPayment($subscription, [
        'provider' => 'paystack',
        'provider_reference' => 'PSK_idempotent_001',
        'status' => PaymentStatus::Succeeded,
        'paid_at' => now()->subDay(),
    ]);

    Http::fake([
        'https://api.paystack.co/transaction/verify/PSK_idempotent_001' => Http::response(
            paystackVerifyPayload('PSK_idempotent_001', metadata: ['payment_id' => $payment->id])
        ),
    ]);

    paystackDriver()->verifyTransaction('PSK_idempotent_001');

    expect($subscription->refresh()->current_period_end?->toDateTimeString())->toBe($oldEnd?->toDateTimeString());

    Event::assertNotDispatched(SubscriptionRenewed::class);
});
