<?php

declare(strict_types=1);

namespace Laracaise\Billing\Exceptions;

final class InvalidPlanChangeException extends BillingException
{
    public static function noActiveSubscription(): self
    {
        return new self('Cannot change plan: no active subscription exists.');
    }

    public static function samePlan(string $slug): self
    {
        return new self("Cannot change plan: already subscribed to \"{$slug}\".");
    }

    public static function reason(string $reason): self
    {
        return new self($reason);
    }
}
