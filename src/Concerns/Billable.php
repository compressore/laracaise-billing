<?php

declare(strict_types=1);

namespace Laracaise\Billing\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laracaise\Billing\BillingContext;
use Laracaise\Billing\BillingManager;
use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\Models\Subscription;

/**
 * Adds billing relationships and the fluent billing() context to any Eloquent model.
 * Mix into User, Team, Organisation, or any other billable entity.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait Billable
{
    /** Entry point for the fluent billing API: $entity->billing()->isActive() etc. */
    public function billing(): BillingContext
    {
        return app(BillingManager::class)->for($this);
    }

    /** @return MorphMany<Subscription, $this> */
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'subscriptionable');
    }

    /** @return MorphMany<Payment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'subscriptionable');
    }
}
