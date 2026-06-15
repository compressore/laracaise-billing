<?php

declare(strict_types=1);

namespace Laracaise\Billing\ValueObjects;

/** Returned by PaymentDriverInterface::initializeTransaction(). */
final readonly class PendingTransaction
{
    /**
     * @param  array<string,mixed>  $meta  Driver-specific fields (not part of the stable contract).
     * @param  array<string,mixed>  $raw  Full raw provider response, stored for audit.
     */
    public function __construct(
        public string $reference,
        public string $checkoutUrl,
        public array $meta = [],
        public array $raw = [],
    ) {}
}
