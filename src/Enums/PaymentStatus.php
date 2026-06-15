<?php

declare(strict_types=1);

namespace Laracaise\Billing\Enums;

enum PaymentStatus: string
{
    case Pending   = 'pending';
    case Succeeded = 'succeeded';
    case Failed    = 'failed';
    case Refunded  = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Pending   => 'Pending',
            self::Succeeded => 'Succeeded',
            self::Failed    => 'Failed',
            self::Refunded  => 'Refunded',
        };
    }
}
