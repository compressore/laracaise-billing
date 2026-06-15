<?php

declare(strict_types=1);

namespace Laracaise\Billing\Services;

use Illuminate\Support\Facades\DB;
use Laracaise\Billing\Exceptions\FeatureNotAvailableException;
use Laracaise\Billing\Exceptions\UsageLimitExceededException;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Models\UsageRecord;

final class UsageService
{
    public function __construct(private readonly FeatureService $features) {}

    /**
     * Sum all UsageRecord quantities for the feature within the subscription's current period.
     * When period dates are not set, all records are counted.
     */
    public function getUsageInPeriod(Subscription $subscription, string $feature): int
    {
        $query = $subscription->usageRecords()->forFeature($feature);

        if ($subscription->current_period_start !== null) {
            $query->where('recorded_at', '>=', $subscription->current_period_start);
        }

        if ($subscription->current_period_end !== null) {
            $query->where('recorded_at', '<=', $subscription->current_period_end);
        }

        return (int) $query->sum('quantity');
    }

    /**
     * Return true if the subscription can absorb $quantity more units of the feature
     * without exceeding its limit. Returns false for unknown or flag features.
     */
    public function canUse(Subscription $subscription, string $feature, int $quantity = 1): bool
    {
        $resolved = $this->features->resolve($subscription, $feature);

        if ($resolved === null || $resolved->isFlag()) {
            return false;
        }

        if ($resolved->isUnlimited()) {
            return true;
        }

        $limit = $resolved->limit() ?? 0;

        if ($limit === 0) {
            return false;
        }

        return ($this->getUsageInPeriod($subscription, $feature) + $quantity) <= $limit;
    }

    /**
     * Record $quantity units of feature usage.
     *
     * For limited features, the limit is re-checked inside a DB transaction to guard
     * against concurrent over-consumption. Behaviour depends on
     * config('laracaise-billing.usage_tracking.locking'):
     *
     *   atomic      — re-checks the aggregate inside the transaction (default)
     *   pessimistic — also acquires a SELECT FOR UPDATE lock on the subscription row
     *   none        — no transaction or re-check (low-traffic only)
     *
     * @throws FeatureNotAvailableException when the feature does not exist or is a flag
     * @throws UsageLimitExceededException when the limit would be exceeded
     */
    public function consume(Subscription $subscription, string $feature, int $quantity = 1): UsageRecord
    {
        $resolved = $this->features->resolve($subscription, $feature);

        if ($resolved === null || $resolved->isFlag()) {
            throw new FeatureNotAvailableException($feature, $subscription->plan->slug ?? '');
        }

        if ($resolved->isUnlimited()) {
            return $this->insertRecord($subscription, $feature, $quantity);
        }

        $limit = $resolved->limit() ?? 0;
        $mode = $this->lockingMode();

        if ($mode === 'none') {
            $used = $this->getUsageInPeriod($subscription, $feature);

            if ($used + $quantity > $limit) {
                throw new UsageLimitExceededException($feature, $limit, $used, $quantity);
            }

            return $this->insertRecord($subscription, $feature, $quantity);
        }

        return DB::transaction(function () use ($subscription, $feature, $quantity, $limit, $mode): UsageRecord {
            if ($mode === 'pessimistic') {
                Subscription::query()->lockForUpdate()->whereKey($subscription->id)->first();
            }

            $used = $this->getUsageInPeriod($subscription, $feature);

            if ($used + $quantity > $limit) {
                throw new UsageLimitExceededException($feature, $limit, $used, $quantity);
            }

            return $this->insertRecord($subscription, $feature, $quantity);
        });
    }

    /**
     * Return the number of units remaining for the feature in the current period.
     * Returns null for unlimited features and flag features.
     *
     * @throws FeatureNotAvailableException when the feature does not exist on the plan
     */
    public function remaining(Subscription $subscription, string $feature): ?int
    {
        $resolved = $this->features->resolve($subscription, $feature);

        if ($resolved === null) {
            throw new FeatureNotAvailableException($feature, $subscription->plan->slug ?? '');
        }

        if ($resolved->isUnlimited() || $resolved->isFlag()) {
            return null;
        }

        $limit = $resolved->limit() ?? 0;

        return max(0, $limit - $this->getUsageInPeriod($subscription, $feature));
    }

    /**
     * Zero out period usage for one or all resettable features by inserting a
     * negative correction record. Append-only: no records are deleted.
     *
     * When $feature is null, all resettable features on the plan are reset.
     */
    public function resetUsage(Subscription $subscription, ?string $feature = null): void
    {
        if ($feature !== null) {
            $this->resetFeature($subscription, $feature);

            return;
        }

        DB::transaction(function () use ($subscription): void {
            foreach ($this->features->allResettable($subscription) as $featureValue) {
                $this->resetFeature($subscription, $featureValue->feature);
            }
        });
    }

    private function resetFeature(Subscription $subscription, string $feature): void
    {
        $used = $this->getUsageInPeriod($subscription, $feature);

        if ($used > 0) {
            $this->insertRecord($subscription, $feature, -$used);
        }
    }

    private function insertRecord(Subscription $subscription, string $feature, int $quantity): UsageRecord
    {
        return $subscription->usageRecords()->create([
            'feature' => $feature,
            'quantity' => $quantity,
        ]);
    }

    private function lockingMode(): string
    {
        $mode = config('laracaise-billing.usage_tracking.locking', 'atomic');

        return is_string($mode) ? $mode : 'atomic';
    }
}
