<?php

declare(strict_types=1);

namespace Laracaise\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Models\Subscription;

/**
 * @extends Factory<Subscription>
 *
 * Always call ->forOwner($model) when the subscriptionable relationship matters.
 * The default morph values are DB-valid placeholders only.
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            // Placeholder morphs — override with forOwner() when testing the relationship
            'subscriptionable_type' => 'owner',
            'subscriptionable_id' => str_pad('1', 26, '0', STR_PAD_LEFT),
            'plan_id' => PlanFactory::new(),
            'name' => 'default',
            'status' => SubscriptionStatus::Active,
            'quantity' => 1,
            'trial_ends_at' => null,
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
            'cancels_at' => null,
            'cancelled_at' => null,
            'provider' => null,
            'provider_id' => null,
            'metadata' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => SubscriptionStatus::Active]);
    }

    public function trialing(int $daysRemaining = 14): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays($daysRemaining),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'cancels_at' => null,
        ]);
    }

    public function cancelledAtPeriodEnd(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Cancelled,
            'cancels_at' => now()->endOfMonth(),
        ]);
    }

    public function pastDue(): static
    {
        return $this->state(['status' => SubscriptionStatus::PastDue]);
    }

    public function pending(): static
    {
        return $this->state(['status' => SubscriptionStatus::Pending]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => SubscriptionStatus::Expired,
            'cancelled_at' => now(),
            'current_period_end' => now()->subSecond(),
        ]);
    }

    public function withProvider(string $provider, ?string $providerId = null): static
    {
        return $this->state([
            'provider' => $provider,
            'provider_id' => $providerId ?? $this->faker->uuid(),
        ]);
    }

    public function forOwner(Model $owner): static
    {
        return $this->state([
            'subscriptionable_type' => $owner->getMorphClass(),
            'subscriptionable_id' => (string) $owner->getKey(),
        ]);
    }
}
