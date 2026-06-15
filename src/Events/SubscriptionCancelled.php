<?php

declare(strict_types=1);

namespace Laracaise\Billing\Events;

use Laracaise\Billing\Models\Subscription;

final readonly class SubscriptionCancelled
{
    public function __construct(
        public Subscription $subscription,
    ) {}
}
