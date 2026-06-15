<?php

declare(strict_types=1);

namespace Laracaise\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Laracaise\Billing\Models\PlanFeature;

/**
 * @extends Factory<PlanFeature>
 */
class PlanFeatureFactory extends Factory
{
    protected $model = PlanFeature::class;

    public function definition(): array
    {
        return [
            'plan_id' => PlanFactory::new(),
            'feature' => $this->faker->unique()->slug(2),
            'value' => (string) $this->faker->numberBetween(10, 10_000),
            'resettable' => true,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(['value' => null]);
    }

    public function flag(bool $enabled = true): static
    {
        return $this->state([
            'value' => $enabled ? 'true' : 'false',
            'resettable' => false,
        ]);
    }

    public function limit(int $value): static
    {
        return $this->state(['value' => (string) $value]);
    }

    public function nonResettable(): static
    {
        return $this->state(['resettable' => false]);
    }
}
