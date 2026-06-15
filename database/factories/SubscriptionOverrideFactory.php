<?php

declare(strict_types=1);

namespace Laracaise\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Laracaise\Billing\Models\SubscriptionOverride;

/**
 * @extends Factory<SubscriptionOverride>
 */
class SubscriptionOverrideFactory extends Factory
{
    protected $model = SubscriptionOverride::class;

    public function definition(): array
    {
        return [
            'subscription_id' => SubscriptionFactory::new(),
            'feature'         => $this->faker->slug(2),
            'value'           => (string) $this->faker->numberBetween(100, 10_000),
            'reason'          => $this->faker->sentence(),
            'expires_at'      => null,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(['value' => null]);
    }

    public function expiring(?\DateTimeInterface $at = null): static
    {
        return $this->state([
            'expires_at' => $at ?? now()->addMonth(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'expires_at' => now()->subDay(),
        ]);
    }
}
