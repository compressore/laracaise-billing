<?php

declare(strict_types=1);

namespace Laracaise\Billing\Drivers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laracaise\Billing\Contracts\PaymentDriverInterface;
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
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\ValueObjects\PendingTransaction;
use RuntimeException;

/**
 * Paystack payment driver.
 *
 * This class reads credentials from config only. Host applications must provide
 * PAYSTACK_* environment values through config/laracaise-billing.php.
 */
final class PaystackDriver implements PaymentDriverInterface
{
    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config = []) {}

    public function name(): string
    {
        return 'paystack';
    }

    /**
     * Charge a reusable Paystack authorization.
     *
     * @param  array{authorization_code?: string, email?: string, metadata?: array<string,mixed>}  $options
     */
    public function charge(Payment $payment, array $options = []): Payment
    {
        $authorizationCode = $this->requiredString($options, 'authorization_code');
        $email = $this->requiredEmail($payment, $options);

        $response = Http::withToken($this->secretKey())
            ->post($this->url('/transaction/charge_authorization'), [
                'authorization_code' => $authorizationCode,
                'email' => $email,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'metadata' => $this->metadata($payment, $options),
            ]);

        $payload = $this->successfulPayload($response);
        $data = $this->data($payload);
        $reference = $this->stringFrom($data, 'reference') ?? $payment->provider_reference ?? $payment->id;

        $payment->update([
            'provider' => 'paystack',
            'provider_reference' => $reference,
            'provider_response' => $payload,
            'status' => $this->paystackStatus($data) === 'success'
                ? PaymentStatus::Succeeded
                : PaymentStatus::Pending,
            'paid_at' => $this->paystackStatus($data) === 'success' ? now() : null,
        ]);

        if ($payment->isSucceeded()) {
            $this->completeSuccessfulPayment($payment);
        }

        return $payment;
    }

    /**
     * Initialize a hosted Paystack checkout transaction.
     *
     * @param  array{email?: string, reference?: string, callback_url?: string, metadata?: array<string,mixed>}  $options
     */
    public function initializeTransaction(Payment $payment, array $options = []): PendingTransaction
    {
        $reference = $this->optionalString($options, 'reference') ?? $payment->provider_reference ?? $payment->id;
        $email = $this->requiredEmail($payment, $options);

        $request = [
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'email' => $email,
            'reference' => $reference,
            'metadata' => $this->metadata($payment, $options),
        ];

        $callbackUrl = $this->optionalString($options, 'callback_url');

        if ($callbackUrl !== null) {
            $request['callback_url'] = $callbackUrl;
        }

        $response = Http::withToken($this->secretKey())
            ->post($this->url('/transaction/initialize'), $request);

        $payload = $this->successfulPayload($response);
        $data = $this->data($payload);
        $providerReference = $this->stringFrom($data, 'reference') ?? $reference;
        $checkoutUrl = $this->stringFrom($data, 'authorization_url');

        if ($checkoutUrl === null) {
            throw new RuntimeException('Paystack initialize response did not include an authorization URL.');
        }

        $payment->update([
            'provider' => 'paystack',
            'provider_reference' => $providerReference,
            'provider_response' => $payload,
            'status' => PaymentStatus::Pending,
            'metadata' => $this->mergeMetadata($payment, $request['metadata']),
        ]);

        return new PendingTransaction(
            reference: $providerReference,
            checkoutUrl: $checkoutUrl,
            meta: [
                'access_code' => $this->stringFrom($data, 'access_code'),
            ],
            raw: $payload,
        );
    }

    public function verifyTransaction(string $reference): Payment
    {
        $response = Http::withToken($this->secretKey())
            ->get($this->url('/transaction/verify/'.rawurlencode($reference)));

        $payload = $this->successfulPayload($response);
        $data = $this->data($payload);
        $providerReference = $this->stringFrom($data, 'reference') ?? $reference;
        $payment = $this->paymentForReference($providerReference, $data);

        if ($payment->isSucceeded()) {
            return $payment;
        }

        if ($payment->isFailed()) {
            return $payment;
        }

        return match ($this->paystackStatus($data)) {
            'success' => $this->markPaymentSucceeded($payment, $providerReference, $payload),
            'failed', 'abandoned' => $this->markPaymentFailed($payment, $providerReference, $payload),
            default => $this->markPaymentPending($payment, $providerReference, $payload),
        };
    }

    public function refund(Payment $payment, ?int $amountInCents = null): Payment
    {
        $response = Http::withToken($this->secretKey())
            ->post($this->url('/refund'), array_filter([
                'transaction' => $payment->provider_reference,
                'amount' => $amountInCents,
            ], static fn (mixed $value): bool => $value !== null));

        $payload = $this->successfulPayload($response);

        return Payment::create([
            'subscriptionable_type' => $payment->subscriptionable_type,
            'subscriptionable_id' => $payment->subscriptionable_id,
            'subscription_id' => $payment->subscription_id,
            'amount' => $amountInCents ?? $payment->amount,
            'currency' => $payment->currency,
            'status' => PaymentStatus::Succeeded,
            'type' => PaymentType::Refund,
            'provider' => 'paystack',
            'provider_reference' => $payment->provider_reference,
            'provider_response' => $payload,
            'paid_at' => now(),
            'metadata' => ['refunded_payment_id' => $payment->id],
        ]);
    }

    /** @param array{email?: string, first_name?: string, last_name?: string, phone?: string} $data */
    public function createCustomer(Model $billable, array $data = []): string
    {
        $email = $this->stringFrom($data, 'email');

        if ($email === null && isset($billable->email) && is_string($billable->email)) {
            $email = $billable->email;
        }

        if ($email === null) {
            throw new InvalidArgumentException('Paystack customers require an email address.');
        }

        $response = Http::withToken($this->secretKey())
            ->post($this->url('/customer'), array_filter([
                'email' => $email,
                'first_name' => $this->stringFrom($data, 'first_name'),
                'last_name' => $this->stringFrom($data, 'last_name'),
                'phone' => $this->stringFrom($data, 'phone'),
            ], static fn (mixed $value): bool => $value !== null));

        $payload = $this->successfulPayload($response);
        $customer = $this->data($payload);
        $customerCode = $this->stringFrom($customer, 'customer_code');

        if ($customerCode === null) {
            throw new RuntimeException('Paystack customer response did not include a customer code.');
        }

        return $customerCode;
    }

    /** @param array<string,mixed> $payload */
    private function markPaymentSucceeded(Payment $payment, string $reference, array $payload): Payment
    {
        $payment->update([
            'provider' => 'paystack',
            'provider_reference' => $reference,
            'provider_response' => $payload,
            'status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);

        $this->completeSuccessfulPayment($payment);

        return $payment;
    }

    /** @param array<string,mixed> $payload */
    private function markPaymentFailed(Payment $payment, string $reference, array $payload): Payment
    {
        $payment->update([
            'provider' => 'paystack',
            'provider_reference' => $reference,
            'provider_response' => $payload,
            'status' => PaymentStatus::Failed,
            'paid_at' => null,
        ]);

        event(new PaymentFailed($payment));

        $subscription = $payment->subscription;

        if ($subscription !== null && ($subscription->isActive() || $subscription->isTrialing())) {
            $subscription->update(['status' => SubscriptionStatus::PastDue]);
            event(new SubscriptionSuspended($subscription));
        }

        return $payment;
    }

    /** @param array<string,mixed> $payload */
    private function markPaymentPending(Payment $payment, string $reference, array $payload): Payment
    {
        $payment->update([
            'provider' => 'paystack',
            'provider_reference' => $reference,
            'provider_response' => $payload,
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);

        return $payment;
    }

    private function completeSuccessfulPayment(Payment $payment): void
    {
        event(new PaymentSucceeded($payment));

        $subscription = $payment->subscription;

        if ($subscription === null) {
            return;
        }

        if ($subscription->status === SubscriptionStatus::Pending || $subscription->status === SubscriptionStatus::Trialing) {
            $this->activateSubscription($subscription);

            return;
        }

        if ($subscription->isActive() || $subscription->isPastDue()) {
            $this->renewSubscription($subscription);
        }
    }

    private function activateSubscription(Subscription $subscription): void
    {
        $start = now();

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'current_period_start' => $start,
            'current_period_end' => $this->periodEnd($subscription, $start),
            'provider' => 'paystack',
        ]);

        event(new SubscriptionActivated($subscription));
    }

    private function renewSubscription(Subscription $subscription): void
    {
        $start = $subscription->current_period_end ?? now();

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $start,
            'current_period_end' => $this->periodEnd($subscription, $start),
            'provider' => 'paystack',
        ]);

        event(new SubscriptionRenewed($subscription));
    }

    private function periodEnd(Subscription $subscription, Carbon $start): ?Carbon
    {
        $plan = $subscription->plan;

        if ($plan === null || $plan->interval === BillingInterval::Once) {
            return null;
        }

        if ($plan->interval === BillingInterval::Weekly) {
            return $start->copy()->addWeeks($plan->interval_count);
        }

        if ($plan->interval === BillingInterval::Monthly) {
            return $start->copy()->addMonths($plan->interval_count);
        }

        return $start->copy()->addYears($plan->interval_count);
    }

    /** @param array<string,mixed> $data */
    private function paymentForReference(string $reference, array $data): Payment
    {
        $payment = Payment::query()
            ->where('provider', 'paystack')
            ->where('provider_reference', $reference)
            ->first();

        if ($payment !== null) {
            return $payment;
        }

        $metadata = $this->arrayFrom($data, 'metadata');
        $paymentId = $this->stringFrom($metadata, 'payment_id');

        if ($paymentId !== null) {
            $payment = Payment::query()->find($paymentId);

            if ($payment !== null) {
                return $payment;
            }
        }

        return $this->createPaymentFromPaystackData($reference, $data, $metadata);
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>  $metadata
     */
    private function createPaymentFromPaystackData(string $reference, array $data, array $metadata): Payment
    {
        $subscription = null;
        $subscriptionId = $this->stringFrom($metadata, 'subscription_id');

        if ($subscriptionId !== null) {
            $subscription = Subscription::query()->find($subscriptionId);
        }

        if ($subscription !== null) {
            $subscriptionableType = $subscription->subscriptionable_type;
            $subscriptionableId = $subscription->subscriptionable_id;
        } else {
            $subscriptionableType = $this->stringFrom($metadata, 'subscriptionable_type');
            $subscriptionableId = $this->stringFrom($metadata, 'subscriptionable_id');
        }

        if ($subscriptionableType === null || $subscriptionableId === null) {
            throw new RuntimeException('Cannot create Paystack payment without subscriptionable metadata.');
        }

        return Payment::create([
            'subscriptionable_type' => $subscriptionableType,
            'subscriptionable_id' => $subscriptionableId,
            'subscription_id' => $subscription !== null ? $subscription->id : null,
            'amount' => $this->intFrom($data, 'amount') ?? 0,
            'currency' => $this->stringFrom($data, 'currency') ?? $this->currency(),
            'status' => PaymentStatus::Pending,
            'type' => PaymentType::Charge,
            'provider' => 'paystack',
            'provider_reference' => $reference,
            'metadata' => $metadata,
        ]);
    }

    /** @return array<string,mixed> */
    private function successfulPayload(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException('Paystack request failed with HTTP status '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('Paystack response was not a JSON object.');
        }

        if (($payload['status'] ?? false) !== true) {
            $message = $payload['message'] ?? 'Paystack request was not successful.';

            throw new RuntimeException(is_string($message) ? $message : 'Paystack request was not successful.');
        }

        return $this->stringKeyedArray($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function data(array $payload): array
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException('Paystack response did not include a data object.');
        }

        return $this->stringKeyedArray($data);
    }

    /** @param array<string,mixed> $data */
    private function paystackStatus(array $data): string
    {
        return $this->stringFrom($data, 'status') ?? 'pending';
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function metadata(Payment $payment, array $options): array
    {
        $metadata = $this->arrayFrom($options, 'metadata');

        $metadata['payment_id'] = $payment->id;

        if ($payment->subscription_id !== null) {
            $metadata['subscription_id'] = $payment->subscription_id;
        }

        $metadata['subscriptionable_type'] = $payment->subscriptionable_type;
        $metadata['subscriptionable_id'] = $payment->subscriptionable_id;

        return $metadata;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function mergeMetadata(Payment $payment, array $metadata): array
    {
        return array_merge($payment->metadata ?? [], $metadata);
    }

    /** @param array<string,mixed> $options */
    private function requiredEmail(Payment $payment, array $options): string
    {
        $email = $this->optionalString($options, 'email');

        if ($email !== null) {
            return $email;
        }

        $owner = $payment->subscriptionable;

        if ($owner !== null && isset($owner->email) && is_string($owner->email)) {
            return $owner->email;
        }

        throw new InvalidArgumentException('Paystack transactions require an email address.');
    }

    /** @param array<string,mixed> $options */
    private function requiredString(array $options, string $key): string
    {
        $value = $this->optionalString($options, $key);

        if ($value === null) {
            throw new InvalidArgumentException("Paystack option [{$key}] is required.");
        }

        return $value;
    }

    /** @param array<string,mixed> $values */
    private function optionalString(array $values, string $key): ?string
    {
        return $this->stringFrom($values, $key);
    }

    /** @param array<string,mixed> $values */
    private function stringFrom(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /** @param array<string,mixed> $values */
    private function intFrom(array $values, string $key): ?int
    {
        $value = $values[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function arrayFrom(array $values, string $key): array
    {
        $value = $values[$key] ?? [];

        return is_array($value) ? $this->stringKeyedArray($value) : [];
    }

    /**
     * @param  array<mixed,mixed>  $values
     * @return array<string,mixed>
     */
    private function stringKeyedArray(array $values): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function secretKey(): string
    {
        $secret = $this->configString('secret_key');

        if ($secret === null) {
            throw new RuntimeException('Paystack secret key is not configured.');
        }

        return $secret;
    }

    private function currency(): string
    {
        $currency = config('laracaise-billing.currency', 'ZAR');

        return is_string($currency) ? $currency : 'ZAR';
    }

    private function baseUrl(): string
    {
        return rtrim($this->configString('base_url') ?? 'https://api.paystack.co', '/');
    }

    private function url(string $path): string
    {
        return $this->baseUrl().'/'.ltrim($path, '/');
    }

    private function configString(string $key): ?string
    {
        $value = $this->config[$key] ?? config("laracaise-billing.drivers.paystack.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }
}
