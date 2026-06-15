<?php

declare(strict_types=1);

namespace Laracaise\Billing\Database\Factories;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laracaise\Billing\Models\UsageRecord;

/**
 * @extends Factory<UsageRecord>
 */
class UsageRecordFactory extends Factory
{
    protected $model = UsageRecord::class;

    public function definition(): array
    {
        return [
            'subscription_id' => SubscriptionFactory::new(),
            'feature' => $this->faker->slug(2),
            'quantity' => $this->faker->numberBetween(1, 100),
            'recorded_at' => now(),
        ];
    }

    public function decrement(int $quantity = 1): static
    {
        return $this->state(['quantity' => -abs($quantity)]);
    }

    public function forFeature(string $feature): static
    {
        return $this->state(['feature' => $feature]);
    }

    public function recordedAt(DateTimeInterface $at): static
    {
        return $this->state(['recorded_at' => $at]);
    }
}
