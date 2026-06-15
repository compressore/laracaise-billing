<?php

declare(strict_types=1);

namespace Laracaise\Billing\Facades;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Laracaise\Billing\BillingContext;
use Laracaise\Billing\BillingManager;
use Laracaise\Billing\Models\Plan;

/**
 * @method static BillingContext for(Model $entity)
 * @method static Plan|null plan(string $slug)
 * @method static Collection<int, Plan> plans()
 *
 * @see BillingManager
 */
final class Billing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BillingManager::class;
    }
}
