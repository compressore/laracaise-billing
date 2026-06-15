<?php

declare(strict_types=1);

namespace Laracaise\Billing\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class NoActiveSubscriptionException extends BillingException
{
    public function __construct(Model $entity, string $name = 'default')
    {
        parent::__construct(sprintf(
            'No active "%s" subscription found for %s.',
            $name,
            $entity->getMorphClass(),
        ));
    }
}
