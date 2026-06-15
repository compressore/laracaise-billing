<?php

declare(strict_types=1);

namespace Laracaise\Billing\Exceptions;

final class FeatureNotAvailableException extends BillingException
{
    public function __construct(public readonly string $feature, string $planSlug = '')
    {
        $context = $planSlug !== '' ? " on plan \"{$planSlug}\"" : '';

        parent::__construct("Feature \"{$feature}\" is not available{$context}.");
    }
}
