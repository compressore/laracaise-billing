<?php

declare(strict_types=1);

namespace Laracaise\Billing\Events;

use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\Models\Subscription;

final readonly class ManualPaymentRecorded
{
    public function __construct(
        public Payment $payment,
        public Subscription $subscription,
    ) {}
}
