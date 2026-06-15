<?php

declare(strict_types=1);

namespace Laracaise\Billing\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Laracaise\Billing\Enums\PaymentStatus;
use Laracaise\Billing\Enums\PaymentType;
use Laracaise\Billing\Models\Payment;

/**
 * @extends Factory<Payment>
 *
 * Always call ->forOwner($model) when the subscriptionable relationship matters.
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            // Placeholder morphs — override with forOwner() when testing the relationship
            'subscriptionable_type' => 'owner',
            'subscriptionable_id' => str_pad('1', 26, '0', STR_PAD_LEFT),
            'subscription_id' => SubscriptionFactory::new(),
            'amount' => $this->faker->numberBetween(500, 50_000),
            'currency' => 'ZAR',
            'status' => PaymentStatus::Succeeded,
            'type' => PaymentType::Charge,
            'provider' => null,
            'provider_reference' => null,
            'provider_response' => null,
            'metadata' => null,
            'paid_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state([
            'status' => PaymentStatus::Pending,
            'paid_at' => null,
        ]);
    }

    public function succeeded(): static
    {
        return $this->state([
            'status' => PaymentStatus::Succeeded,
            'paid_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => PaymentStatus::Failed,
            'paid_at' => null,
        ]);
    }

    public function refund(): static
    {
        return $this->state([
            'type' => PaymentType::Refund,
            'status' => PaymentStatus::Succeeded,
        ]);
    }

    public function withProvider(string $provider, ?string $reference = null): static
    {
        return $this->state([
            'provider' => $provider,
            'provider_reference' => $reference ?? $this->faker->uuid(),
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
