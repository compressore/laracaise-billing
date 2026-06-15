<?php

declare(strict_types=1);

namespace Laracaise\Billing\Enums;

enum PaymentType: string
{
    case Charge = 'charge';
    case Refund = 'refund';
}
