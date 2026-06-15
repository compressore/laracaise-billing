<?php

declare(strict_types=1);

namespace Laracaise\Billing\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\Models\Subscription;

/**
 * Adds billing relationships to any Eloquent model.
 * Mix into User, Team, Organisation, or any other billable entity.
 * No billing logic lives here — that comes via BillingContext in a later phase.
 */
trait Billable
{
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'subscriptionable');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'subscriptionable');
    }
}
