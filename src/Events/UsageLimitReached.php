<?php

declare(strict_types=1);

namespace Laracaise\Billing\Events;

use Laracaise\Billing\Models\Subscription;

final readonly class UsageLimitReached
{
    public function __construct(
        public Subscription $subscription,
        public string $feature,
        public int $limit,
        public int $used,
        public int $requested,
    ) {}
}
