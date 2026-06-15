<?php

declare(strict_types=1);

namespace Laracaise\Billing\Events;

use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\Subscription;

final readonly class PlanChanged
{
    public function __construct(
        public Subscription $subscription,
        public Plan $previousPlan,
        public Plan $newPlan,
    ) {}
}
