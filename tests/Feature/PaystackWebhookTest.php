<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laracaise\Billing\Enums\BillingInterval;
use Laracaise\Billing\Enums\PaymentStatus;
use Laracaise\Billing\Enums\PaymentType;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Tests\Fixtures\BillableModel;

beforeEach(function () {
    config()->set('laracaise-billing.drivers.paystack.secret_key', 'sk_test_webhook');
    config()->set('laracaise-billing.drivers.paystack.webhook_secret', 'whsec_test_webhook');
    config()->set('laracaise-billing.drivers.paystack.base_url', 'https://api.paystack.co');
});

function paystackWebhookPayment(string $reference, SubscriptionStatus $subscriptionStatus = SubscriptionStatus::Pending): array
{
    $owner = BillableModel::create(['name' => 'Webhook Owner']);
    $plan = Plan::factory()->create([
        'interval' => BillingInterval::Monthly,
        'currency' => 'ZAR',
    ]);
    $subscription = Subscription::factory()->forOwner($owner)->create([
        'plan_id' => $plan->id,
        'provider' => 'paystack',
        'status' => $subscriptionStatus,
        'current_period_start' => $subscriptionStatus === SubscriptionStatus::Pending ? null : now()->startOfMonth(),
        'current_period_end' => $subscriptionStatus === SubscriptionStatus::Pending ? null : now()->endOfMonth(),
    ]);
    $payment = Payment::factory()->forOwner($owner)->create([
        'subscription_id' => $subscription->id,
        'amount' => 12_500,
        'currency' => 'ZAR',
        'status' => PaymentStatus::Pending,
        'type' => PaymentType::Charge,
        'provider' => 'paystack',
        'provider_reference' => $reference,
        'paid_at' => null,
    ]);

    return [$subscription, $payment];
}

function signedPaystackWebhook(array $payload, string $secret = 'whsec_test_webhook'): array
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);

    return [$json, hash_hmac('sha512', $json, $secret)];
}

function postPaystackWebhook($test, string $json, string $signature): TestResponse
{
    return $test->call(
        'POST',
        '/billing/webhook/paystack',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ],
        $json
    );
}

it('rejects Paystack webhooks with an invalid signature', function () {
    Http::fake();

    [$json] = signedPaystackWebhook([
        'event' => 'charge.success',
        'data' => ['reference' => 'PSK_bad_sig'],
    ]);

    postPaystackWebhook($this, $json, 'invalid')->assertUnauthorized();

    Http::assertNothingSent();
});

it('handles successful Paystack payment webhooks', function () {
    [$subscription, $payment] = paystackWebhookPayment('PSK_webhook_success');

    Http::fake([
        'https://api.paystack.co/transaction/verify/PSK_webhook_success' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'PSK_webhook_success',
                'status' => 'success',
                'amount' => 12_500,
                'currency' => 'ZAR',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'subscription_id' => $subscription->id,
                ],
            ],
        ]),
    ]);

    [$json, $signature] = signedPaystackWebhook([
        'event' => 'charge.success',
        'data' => ['reference' => 'PSK_webhook_success'],
    ]);

    postPaystackWebhook($this, $json, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('does not process duplicate successful Paystack webhooks twice', function () {
    [$subscription, $payment] = paystackWebhookPayment('PSK_webhook_duplicate', SubscriptionStatus::Active);

    Http::fake([
        'https://api.paystack.co/transaction/verify/PSK_webhook_duplicate' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'PSK_webhook_duplicate',
                'status' => 'success',
                'amount' => 12_500,
                'currency' => 'ZAR',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'subscription_id' => $subscription->id,
                ],
            ],
        ]),
    ]);

    [$json, $signature] = signedPaystackWebhook([
        'event' => 'charge.success',
        'data' => ['reference' => 'PSK_webhook_duplicate'],
    ]);

    postPaystackWebhook($this, $json, $signature)->assertOk();

    $renewedEnd = $subscription->refresh()->current_period_end?->toDateTimeString();

    postPaystackWebhook($this, $json, $signature)->assertOk();

    expect(Payment::count())->toBe(1)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($subscription->refresh()->current_period_end?->toDateTimeString())->toBe($renewedEnd);
});

it('handles failed Paystack payment webhooks', function () {
    [$subscription, $payment] = paystackWebhookPayment('PSK_webhook_failed', SubscriptionStatus::Active);

    Http::fake([
        'https://api.paystack.co/transaction/verify/PSK_webhook_failed' => Http::response([
            'status' => true,
            'data' => [
                'reference' => 'PSK_webhook_failed',
                'status' => 'failed',
                'amount' => 12_500,
                'currency' => 'ZAR',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'subscription_id' => $subscription->id,
                ],
            ],
        ]),
    ]);

    [$json, $signature] = signedPaystackWebhook([
        'event' => 'charge.failed',
        'data' => ['reference' => 'PSK_webhook_failed'],
    ]);

    postPaystackWebhook($this, $json, $signature)->assertOk();

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->paid_at)->toBeNull()
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::PastDue);
});

it('ignores unsupported Paystack webhook events', function () {
    [$json, $signature] = signedPaystackWebhook([
        'event' => 'customer.created',
        'data' => ['reference' => 'PSK_ignored'],
    ]);

    Http::fake();

    postPaystackWebhook($this, $json, $signature)->assertOk();

    Http::assertNothingSent();
});
