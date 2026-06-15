<?php

declare(strict_types=1);

namespace Laracaise\Billing\Services;

use Laracaise\Billing\Models\PlanFeature;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\ValueObjects\FeatureValue;

final class FeatureService
{
    /**
     * Resolve the effective value for a feature on the given subscription.
     *
     * Override resolution order:
     *   1. Most recent active SubscriptionOverride (value only; resettable comes from plan)
     *   2. PlanFeature on the subscription's plan
     *   3. null — feature does not exist on this plan
     */
    public function resolve(Subscription $subscription, string $feature): ?FeatureValue
    {
        $plan = $subscription->plan;

        if ($plan === null) {
            return null;
        }

        $planFeature = $plan->features()
            ->where('feature', $feature)
            ->first();

        if ($planFeature === null) {
            return null;
        }

        // Active per-subscription override takes precedence over the plan value.
        $override = $subscription->overrides()
            ->forFeature($feature)
            ->active()
            ->latest('created_at')
            ->first();

        if ($override !== null) {
            return new FeatureValue(
                feature: $feature,
                value: $override->value,
                resettable: $planFeature->resettable,
                source: 'override',
            );
        }

        return new FeatureValue(
            feature: $feature,
            value: $planFeature->value,
            resettable: $planFeature->resettable,
            source: 'plan',
        );
    }

    /**
     * Return resolved FeatureValues for every resettable feature on the subscription's plan.
     * Used by UsageService::resetUsage() when no specific feature is given.
     *
     * @return list<FeatureValue>
     */
    public function allResettable(Subscription $subscription): array
    {
        $plan = $subscription->plan;

        if ($plan === null) {
            return [];
        }

        $result = [];

        /** @var PlanFeature $planFeature */
        foreach ($plan->features()->resettable()->get() as $planFeature) {
            $resolved = $this->resolve($subscription, $planFeature->feature);

            if ($resolved !== null) {
                $result[] = $resolved;
            }
        }

        return $result;
    }
}
