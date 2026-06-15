<?php

declare(strict_types=1);

namespace Laracaise\Billing\Enums;

enum SubscriptionStatus: string
{
    case Pending   = 'pending';
    case Trialing  = 'trialing';
    case Active    = 'active';
    case PastDue   = 'past_due';
    case Cancelled = 'cancelled';
    case Expired   = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Trialing  => 'Trialing',
            self::Active    => 'Active',
            self::PastDue   => 'Past Due',
            self::Cancelled => 'Cancelled',
            self::Expired   => 'Expired',
        };
    }

    public function isAccessible(): bool
    {
        return match ($this) {
            self::Active, self::Trialing => true,
            default                      => false,
        };
    }
}
