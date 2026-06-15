<?php

declare(strict_types=1);

namespace Laracaise\Billing\Contracts;

use Illuminate\Database\Eloquent\Model;
use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\ValueObjects\PendingTransaction;

interface PaymentDriverInterface
{
    /** Unique machine name stored on Payment and Subscription records. */
    public function name(): string;

    /**
     * Directly charge a stored payment method for a pending Payment record.
     * Returns the updated Payment.
     *
     * @param  array<string,mixed>  $options
     */
    public function charge(Payment $payment, array $options = []): Payment;

    /**
     * Begin a hosted/redirect checkout flow.
     * Returns a PendingTransaction with the checkout URL and driver-specific meta.
     *
     * @param  array<string,mixed>  $options
     */
    public function initializeTransaction(Payment $payment, array $options = []): PendingTransaction;

    /**
     * Verify a completed transaction by the provider's reference string.
     * Must be idempotent — return the existing Payment if already verified.
     */
    public function verifyTransaction(string $reference): Payment;

    /**
     * Issue a full or partial refund. Amount in smallest currency unit.
     * Null amount means full refund.
     */
    public function refund(Payment $payment, ?int $amountInCents = null): Payment;

    /**
     * Create or retrieve a provider-side customer record for a billable model.
     * Returns the provider's customer identifier.
     *
     * @param  array<string,mixed>  $data
     */
    public function createCustomer(Model $billable, array $data = []): string;
}
