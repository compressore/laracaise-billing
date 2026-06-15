<?php

declare(strict_types=1);

namespace Laracaise\Billing;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laracaise\Billing\Contracts\PaymentDriverInterface;
use Laracaise\Billing\Drivers\ManualDriver;
use Laracaise\Billing\Drivers\PaystackDriver;
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

    /** Resolve a supported payment driver by name, or the configured default. */
    public function driver(?string $name = null): PaymentDriverInterface
    {
        $configuredDriver = config('laracaise-billing.driver', 'manual');
        $driver = $name ?? (is_string($configuredDriver) ? $configuredDriver : 'manual');

        return match ($driver) {
            'manual' => new ManualDriver,
            'paystack' => new PaystackDriver($this->driverConfig('paystack')),
            default => throw new InvalidArgumentException("Unsupported billing driver [{$driver}]."),
        };
    }

    /** @return array<string,mixed> */
    private function driverConfig(string $driver): array
    {
        $config = config("laracaise-billing.drivers.{$driver}", []);

        return is_array($config) ? $config : [];
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
