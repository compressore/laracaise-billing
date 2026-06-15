<?php

declare(strict_types=1);

namespace Laracaise\Billing;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Services\FeatureService;
use Laracaise\Billing\Services\UsageService;

/**
 * Central entry point for the billing engine.
 * Bound as a singleton and exposed via the Billing facade.
 */
final class BillingManager
{
    public function __construct(
        private readonly FeatureService $features,
        private readonly UsageService $usage,
    ) {}

    /** Return a BillingContext scoped to the given billable entity. */
    public function for(Model $entity): BillingContext
    {
        return new BillingContext($entity, $this->features, $this->usage);
    }

    /** Retrieve an active plan by slug. Returns null if not found or inactive. */
    public function plan(string $slug): ?Plan
    {
        return Plan::query()->active()->where('slug', $slug)->first();
    }

    /**
     * Retrieve all active plans in display order.
     *
     * @return Collection<int, Plan>
     */
    public function plans(): Collection
    {
        return Plan::query()->active()->ordered()->get();
    }
}
