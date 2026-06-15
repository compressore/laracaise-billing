<?php

declare(strict_types=1);

namespace Laracaise\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Laracaise\Billing\Enums\BillingInterval;
use Laracaise\Billing\Models\Plan;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name'           => ucwords($this->faker->unique()->words(2, true)),
            'slug'           => $this->faker->unique()->slug(2),
            'description'    => $this->faker->sentence(),
            'amount'         => $this->faker->numberBetween(500, 50_000),
            'currency'       => 'ZAR',
            'interval'       => BillingInterval::Monthly,
            'interval_count' => 1,
            'trial_days'     => 0,
            'is_active'      => true,
            'sort_order'     => 0,
            'metadata'       => null,
        ];
    }

    public function monthly(): static
    {
        return $this->state(['interval' => BillingInterval::Monthly]);
    }

    public function yearly(): static
    {
        return $this->state(['interval' => BillingInterval::Yearly]);
    }

    public function weekly(): static
    {
        return $this->state(['interval' => BillingInterval::Weekly]);
    }

    public function once(): static
    {
        return $this->state(['interval' => BillingInterval::Once]);
    }

    public function free(): static
    {
        return $this->state(['amount' => 0]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withTrial(int $days = 14): static
    {
        return $this->state(['trial_days' => $days]);
    }
}
