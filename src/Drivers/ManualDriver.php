<?php

declare(strict_types=1);

namespace Laracaise\Billing\Drivers;

use BadMethodCallException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laracaise\Billing\Contracts\PaymentDriverInterface;
use Laracaise\Billing\Enums\BillingInterval;
use Laracaise\Billing\Enums\PaymentStatus;
use Laracaise\Billing\Enums\PaymentType;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Events\ManualPaymentRecorded;
use Laracaise\Billing\Events\SubscriptionActivated;
use Laracaise\Billing\Events\SubscriptionCancelled;
use Laracaise\Billing\Events\SubscriptionCreated;
use Laracaise\Billing\Events\SubscriptionExpired;
use Laracaise\Billing\Events\SubscriptionExtended;
use Laracaise\Billing\Events\SubscriptionRenewed;
use Laracaise\Billing\Events\SubscriptionResumed;
use Laracaise\Billing\Events\SubscriptionSuspended;
use Laracaise\Billing\Exceptions\InvalidTransitionException;
use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\ValueObjects\PendingTransaction;

/**
 * Out-of-band payment driver for EFT, bank transfers, cash, and purchase orders.
 *
 * Makes no HTTP calls. Subscription lifecycle is managed explicitly by an operator
 * rather than driven by payment-gateway webhooks.
 */
final class ManualDriver implements PaymentDriverInterface
{
    public function name(): string
    {
        return 'manual';
    }

    // -------------------------------------------------------------------------
    // PaymentDriverInterface — payment operations
    // -------------------------------------------------------------------------

    /**
     * Record a pending charge for offline collection.
     * The operator confirms receipt via verifyTransaction() or recordPayment().
     *
     * @param  array<string,mixed>  $options
     */
    public function charge(Payment $payment, array $options = []): Payment
    {
        $payment->update([
            'provider' => 'manual',
            'status' => PaymentStatus::Pending,
        ]);

        return $payment;
    }

    /**
     * Not supported by the manual driver — manual billing has no checkout URL.
     *
     * @param  array<string,mixed>  $options
     *
     * @throws BadMethodCallException
     */
    public function initializeTransaction(Payment $payment, array $options = []): PendingTransaction
    {
        throw new BadMethodCallException(
            'The manual driver does not support hosted checkout. Use charge() to create a pending payment record.'
        );
    }

    /**
     * Mark a pending payment as succeeded.
     * Called when the operator confirms an offline payment was received.
     *
     * @throws \RuntimeException when no pending manual payment with that reference exists
     */
    public function verifyTransaction(string $reference): Payment
    {
        $payment = Payment::query()
            ->where('provider', 'manual')
            ->where('provider_reference', $reference)
            ->firstOrFail();

        $payment->update([
            'status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);

        $subscription = $payment->subscription;

        if ($subscription !== null) {
            event(new ManualPaymentRecorded($payment, $subscription));
        }

        return $payment;
    }

    /**
     * Create a refund record for a manual payment.
     * The refund amount defaults to the full payment amount when null.
     */
    public function refund(Payment $payment, ?int $amountInCents = null): Payment
    {
        return Payment::create([
            'subscriptionable_type' => $payment->subscriptionable_type,
            'subscriptionable_id' => $payment->subscriptionable_id,
            'subscription_id' => $payment->subscription_id,
            'amount' => $amountInCents ?? $payment->amount,
            'currency' => $payment->currency,
            'status' => PaymentStatus::Succeeded,
            'type' => PaymentType::Refund,
            'provider' => 'manual',
            'provider_reference' => null,
            'paid_at' => now(),
            'metadata' => ['refunded_payment_id' => $payment->id],
        ]);
    }

    /**
     * No-op for the manual driver — no remote customer record is needed.
     * Returns the billable model's primary key as a string.
     *
     * @param  array<string,mixed>  $data
     */
    public function createCustomer(Model $billable, array $data = []): string
    {
        return $this->keyToString($billable->getKey());
    }

    // -------------------------------------------------------------------------
    // ManualDriver — subscription lifecycle
    // -------------------------------------------------------------------------

    /**
     * Create a new subscription in Pending status (or Trialing when trial days > 0).
     *
     * Supported options:
     *   'quantity'   int   Number of seats (default 1)
     *   'trial_days' int   Override the plan's trial_days (0 = no trial)
     *   'metadata'   array Arbitrary key/value stored on the subscription
     *
     * @param  array{quantity?: int, trial_days?: int, metadata?: array<string,mixed>}  $options
     */
    public function createSubscription(
        Model $billable,
        Plan $plan,
        string $name = 'default',
        array $options = [],
    ): Subscription {
        $quantity = (int) ($options['quantity'] ?? 1);
        $trialDays = array_key_exists('trial_days', $options)
            ? (int) $options['trial_days']
            : $plan->trial_days;

        $trialing = $trialDays > 0;

        $subscription = Subscription::create([
            'subscriptionable_type' => $billable->getMorphClass(),
            'subscriptionable_id' => $this->keyToString($billable->getKey()),
            'plan_id' => $plan->id,
            'name' => $name,
            'status' => $trialing ? SubscriptionStatus::Trialing : SubscriptionStatus::Pending,
            'quantity' => $quantity,
            'trial_ends_at' => $trialing ? now()->addDays($trialDays) : null,
            'current_period_start' => null,
            'current_period_end' => null,
            'provider' => 'manual',
            'metadata' => $options['metadata'] ?? null,
        ]);

        event(new SubscriptionCreated($subscription));

        return $subscription;
    }

    /**
     * Activate a Pending or Trialing subscription.
     * Sets the billing period starting from now.
     *
     * @throws InvalidTransitionException when the subscription is not in Pending or Trialing status
     */
    public function activateSubscription(
        Subscription $subscription,
        ?Carbon $activatedAt = null,
    ): Subscription {
        if (
            $subscription->status !== SubscriptionStatus::Pending
            && $subscription->status !== SubscriptionStatus::Trialing
        ) {
            throw InvalidTransitionException::from($subscription->status, SubscriptionStatus::Active);
        }

        $plan = $subscription->plan;

        $start = $activatedAt ?? now();
        $end = $plan !== null ? $this->computePeriodEnd($start, $plan) : null;

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'current_period_start' => $start,
            'current_period_end' => $end,
        ]);

        event(new SubscriptionActivated($subscription));

        return $subscription;
    }

    /**
     * Advance the subscription's billing period to the next cycle.
     * Optionally accepts a period start date; defaults to the current period end.
     *
     * @throws InvalidTransitionException when the subscription is not Active or PastDue,
     *                                    or when the plan interval is Once
     */
    public function renewSubscription(
        Subscription $subscription,
        ?Carbon $periodStart = null,
    ): Subscription {
        if (! $subscription->isActive() && ! $subscription->isPastDue()) {
            throw InvalidTransitionException::from($subscription->status, SubscriptionStatus::Active);
        }

        $plan = $subscription->plan;

        if ($plan === null) {
            throw new \RuntimeException('Subscription has no associated plan.');
        }

        if ($plan->interval === BillingInterval::Once) {
            throw new InvalidTransitionException('One-time subscriptions cannot be renewed.');
        }

        $newStart = $periodStart ?? $subscription->current_period_end ?? now();
        $newEnd = $this->computePeriodEnd($newStart, $plan);

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $newStart,
            'current_period_end' => $newEnd,
        ]);

        event(new SubscriptionRenewed($subscription));

        return $subscription;
    }

    /**
     * Extend the current billing period by the given number of days.
     *
     * @throws InvalidTransitionException when the subscription cannot be extended
     *                                    (Cancelled or Expired)
     */
    public function extendSubscription(Subscription $subscription, int $days): Subscription
    {
        if (
            $subscription->status === SubscriptionStatus::Cancelled
            || $subscription->status === SubscriptionStatus::Expired
        ) {
            throw InvalidTransitionException::from($subscription->status, SubscriptionStatus::Active);
        }

        $currentEnd = $subscription->current_period_end ?? now();

        $subscription->update([
            'current_period_end' => $currentEnd->copy()->addDays($days),
        ]);

        event(new SubscriptionExtended($subscription));

        return $subscription;
    }

    /**
     * Suspend an active subscription (marks it as past_due).
     * Feature access is revoked while suspended.
     *
     * @throws InvalidTransitionException when the subscription is not Active
     */
    public function suspendSubscription(Subscription $subscription): Subscription
    {
        if (! $subscription->isActive()) {
            throw InvalidTransitionException::from($subscription->status, SubscriptionStatus::PastDue);
        }

        $subscription->update(['status' => SubscriptionStatus::PastDue]);

        event(new SubscriptionSuspended($subscription));

        return $subscription;
    }

    /**
     * Resume a suspended (past_due) subscription to active.
     *
     * @throws InvalidTransitionException when the subscription is not PastDue
     */
    public function resumeSubscription(Subscription $subscription): Subscription
    {
        if (! $subscription->isPastDue()) {
            throw InvalidTransitionException::from($subscription->status, SubscriptionStatus::Active);
        }

        $subscription->update(['status' => SubscriptionStatus::Active]);

        event(new SubscriptionResumed($subscription));

        return $subscription;
    }

    /**
     * Cancel a subscription.
     *
     * When $immediate is false (default), access continues until current_period_end.
     * When $immediate is true, access ends immediately (current_period_end set to now).
     */
    public function cancelSubscription(
        Subscription $subscription,
        bool $immediate = false,
    ): Subscription {
        $attrs = [
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
        ];

        if ($immediate) {
            $attrs['current_period_end'] = now();
        }

        $subscription->update($attrs);

        event(new SubscriptionCancelled($subscription));

        return $subscription;
    }

    /**
     * Hard-expire a subscription with no grace period.
     * Used for subscriptions that have definitively ended (e.g. no payment after cancellation).
     */
    public function expireSubscription(Subscription $subscription): Subscription
    {
        $subscription->update([
            'status' => SubscriptionStatus::Expired,
            'current_period_end' => now(),
            'cancelled_at' => $subscription->cancelled_at ?? now(),
        ]);

        event(new SubscriptionExpired($subscription));

        return $subscription;
    }

    /**
     * Record a successful manual payment (EFT received, cash collected, etc.).
     * Creates a Succeeded Payment record with provider = 'manual'.
     *
     * Supported options:
     *   'currency'   string  ISO 4217 code (defaults to the plan's currency, then 'ZAR')
     *   'paid_at'    Carbon  When payment was received (default now())
     *   'metadata'   array   Arbitrary key/value data
     *
     * @param  array{currency?: string, paid_at?: Carbon, metadata?: array<string,mixed>}  $options
     */
    public function recordPayment(
        Subscription $subscription,
        int $amount,
        string $reference,
        array $options = [],
    ): Payment {
        $plan = $subscription->plan;
        $currency = ($options['currency'] ?? null)
            ?? ($plan !== null ? $plan->currency : 'ZAR');

        $payment = Payment::create([
            'subscriptionable_type' => $subscription->subscriptionable_type,
            'subscriptionable_id' => $subscription->subscriptionable_id,
            'subscription_id' => $subscription->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentStatus::Succeeded,
            'type' => PaymentType::Charge,
            'provider' => 'manual',
            'provider_reference' => $reference,
            'paid_at' => $options['paid_at'] ?? now(),
            'metadata' => $options['metadata'] ?? null,
        ]);

        event(new ManualPaymentRecorded($payment, $subscription));

        return $payment;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function computePeriodEnd(Carbon $start, Plan $plan): Carbon
    {
        return match ($plan->interval) {
            BillingInterval::Weekly => $start->copy()->addWeeks($plan->interval_count),
            BillingInterval::Monthly => $start->copy()->addMonths($plan->interval_count),
            BillingInterval::Yearly => $start->copy()->addYears($plan->interval_count),
            BillingInterval::Once => throw new \LogicException('Once-off plans do not have a recurring period end.'),
        };
    }

    private function keyToString(mixed $key): string
    {
        return match (true) {
            is_string($key) => $key,
            is_int($key) => (string) $key,
            default => throw new \UnexpectedValueException('Model primary key must be a string or integer.'),
        };
    }
}
