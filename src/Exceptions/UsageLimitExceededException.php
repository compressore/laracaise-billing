<?php

declare(strict_types=1);

namespace Laracaise\Billing\Exceptions;

final class UsageLimitExceededException extends BillingException
{
    public function __construct(
        public readonly string $feature,
        public readonly int $limit,
        public readonly int $used,
        public readonly int $requested,
    ) {
        parent::__construct(sprintf(
            'Usage limit exceeded for feature "%s": limit %d, used %d, requested %d.',
            $feature,
            $limit,
            $used,
            $requested,
        ));
    }
}
