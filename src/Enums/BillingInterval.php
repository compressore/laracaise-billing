<?php

declare(strict_types=1);

namespace Laracaise\Billing\Enums;

enum BillingInterval: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';
    case Once = 'once';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
            self::Once => 'One-time',
        };
    }

    public function isRecurring(): bool
    {
        return $this !== self::Once;
    }
}
