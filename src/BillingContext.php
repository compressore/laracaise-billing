<?php

declare(strict_types=1);

namespace Laracaise\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Exceptions\FeatureNotAvailableException;
use Laracaise\Billing\Exceptions\NoActiveSubscriptionException;
use Laracaise\Billing\Exceptions\UsageLimitExceededException;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Models\UsageRecord;
use Laracaise\Billing\Services\FeatureService;
use Laracaise\Billing\Services\UsageService;

/**
 * Short-lived context object scoped to one billable entity.
 *
 * Obtain via:
 *   Billing::for($entity)->...
 *   $entity->billing()->...
 *
 * Every method that reads the active subscription defaults to the 'default'
 * subscription name. Pass a different name to work with named subscriptions
 * (e.g. 'addon', 'enterprise').
 */
final class BillingContext
{
    public function __construct(
        private readonly Model $entity,
        private readonly FeatureService $features,
        private readonly UsageService $usage,
    ) {}

    // -------------------------------------------------------------------------
    // Subscription & plan queries
    // -------------------------------------------------------------------------

    /**
     * Return the current subscription (active, trialing, past_due, pending, or
     * cancelled-but-in-grace-period). Returns null when no current subscription
     * exists for the given name.
     */
    public function subscription(string $name = 'default'): ?Subscription
    {
        return Subscription::query()
            ->forOwner($this->entity)
            ->withName($name)
            ->where(function (Builder $q): void {
                $q->whereIn('status', [
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::Trialing->value,
                    SubscriptionStatus::PastDue->value,
                    SubscriptionStatus::Pending->value,
                ])->orWhere(function (Builder $q): void {
                    // Cancelled-but-in-grace-period: payment succeeded, period hasn't ended yet.
                    $q->where('status', SubscriptionStatus::Cancelled->value)
                        ->whereNotNull('current_period_end')
                        ->where('current_period_end', '>', now());
                });
            })
            ->latest()
            ->first();
    }

    /** Return the Plan of the current subscription, or null if not subscribed. */
    public function plan(string $name = 'default'): ?Plan
    {
        return $this->subscription($name)?->plan;
    }

    // -------------------------------------------------------------------------
    // Status checks
    // -------------------------------------------------------------------------

    /** True when the entity has a subscription in Active status. */
    public function isActive(string $name = 'default'): bool
    {
        return Subscription::query()
            ->forOwner($this->entity)
            ->withName($name)
            ->active()
            ->exists();
    }

    /** True when the entity has a subscription in Trialing status. */
    public function onTrial(string $name = 'default'): bool
    {
        return Subscription::query()
            ->forOwner($this->entity)
            ->withName($name)
            ->trialing()
            ->exists();
    }

    /** True when the entity has a subscription in PastDue status (payment failed). */
    public function isSuspended(string $name = 'default'): bool
    {
        return Subscription::query()
            ->forOwner($this->entity)
            ->withName($name)
            ->pastDue()
            ->exists();
    }

    // -------------------------------------------------------------------------
    // Feature checks
    // -------------------------------------------------------------------------

    /**
     * Return true when the entity's plan includes the feature and it is enabled:
     *  - flag feature  → true only when value is 'true'
     *  - numeric limit → true when limit > 0 or unlimited
     *  - no subscription / feature not on plan → false (never throws)
     */
    public function hasFeature(string $feature, string $name = 'default'): bool
    {
        $subscription = $this->accessibleSubscription($name);

        if ($subscription === null) {
            return false;
        }

        $resolved = $this->features->resolve($subscription, $feature);

        if ($resolved === null) {
            return false;
        }

        if ($resolved->isFlag()) {
            return $resolved->flagValue();
        }

        if ($resolved->isUnlimited()) {
            return true;
        }

        return ($resolved->limit() ?? 0) > 0;
    }

    /**
     * Return the configured hard limit for a numeric feature.
     * Returns null when the feature is unlimited.
     *
     * @throws NoActiveSubscriptionException when there is no accessible subscription
     * @throws FeatureNotAvailableException  when the feature does not exist or is a flag
     */
    public function limit(string $feature, string $name = 'default'): ?int
    {
        $subscription = $this->requireAccessibleSubscription($name);
        $resolved     = $this->features->resolve($subscription, $feature);

        if ($resolved === null || $resolved->isFlag()) {
            throw new FeatureNotAvailableException($feature);
        }

        return $resolved->limit();
    }

    // -------------------------------------------------------------------------
    // Usage tracking
    // -------------------------------------------------------------------------

    /**
     * Return true when the entity can consume $quantity more units of the feature
     * without exceeding the limit. Returns false if there is no accessible
     * subscription — never throws.
     */
    public function canUse(string $feature, int $quantity = 1, string $name = 'default'): bool
    {
        $subscription = $this->accessibleSubscription($name);

        if ($subscription === null) {
            return false;
        }

        return $this->usage->canUse($subscription, $feature, $quantity);
    }

    /**
     * Record $quantity units of usage for the feature.
     *
     * @throws NoActiveSubscriptionException when there is no accessible subscription
     * @throws FeatureNotAvailableException  when the feature does not exist or is a flag
     * @throws UsageLimitExceededException   when the limit would be exceeded
     */
    public function consume(string $feature, int $quantity = 1, string $name = 'default'): UsageRecord
    {
        return $this->usage->consume($this->requireAccessibleSubscription($name), $feature, $quantity);
    }

    /**
     * Return the number of units remaining for the feature this period.
     * Returns null for unlimited features.
     *
     * @throws NoActiveSubscriptionException when there is no accessible subscription
     * @throws FeatureNotAvailableException  when the feature does not exist on the plan
     */
    public function remaining(string $feature, string $name = 'default'): ?int
    {
        return $this->usage->remaining($this->requireAccessibleSubscription($name), $feature);
    }

    /**
     * Zero out period usage by inserting a negative correction record.
     * When $feature is null, all resettable features on the plan are reset.
     *
     * @throws NoActiveSubscriptionException when there is no accessible subscription
     */
    public function resetUsage(?string $feature = null, string $name = 'default'): void
    {
        $this->usage->resetUsage($this->requireAccessibleSubscription($name), $feature);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Return a subscription the entity can actively use features on:
     * Active, Trialing, or Cancelled-but-in-grace-period.
     * PastDue subscriptions are intentionally excluded — suspended accounts
     * lose feature access.
     */
    private function accessibleSubscription(string $name): ?Subscription
    {
        return Subscription::query()
            ->forOwner($this->entity)
            ->withName($name)
            ->where(function (Builder $q): void {
                $q->whereIn('status', [
                    SubscriptionStatus::Active->value,
                    SubscriptionStatus::Trialing->value,
                ])->orWhere(function (Builder $q): void {
                    $q->where('status', SubscriptionStatus::Cancelled->value)
                        ->whereNotNull('current_period_end')
                        ->where('current_period_end', '>', now());
                });
            })
            ->latest()
            ->first();
    }

    /** @throws NoActiveSubscriptionException */
    private function requireAccessibleSubscription(string $name): Subscription
    {
        return $this->accessibleSubscription($name)
            ?? throw new NoActiveSubscriptionException($this->entity, $name);
    }
}
