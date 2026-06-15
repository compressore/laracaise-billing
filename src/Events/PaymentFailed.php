<?php

declare(strict_types=1);

namespace Laracaise\Billing\Events;

use Laracaise\Billing\Models\Payment;

final readonly class PaymentFailed
{
    public function __construct(
        public Payment $payment,
    ) {}
}
