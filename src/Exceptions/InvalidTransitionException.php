<?php

declare(strict_types=1);

namespace Laracaise\Billing\Exceptions;

use Laracaise\Billing\Enums\SubscriptionStatus;

final class InvalidTransitionException extends BillingException
{
    public static function from(SubscriptionStatus $from, SubscriptionStatus $to): self
    {
        return new self(
            "Cannot transition subscription from [{$from->value}] to [{$to->value}]."
        );
    }
}
