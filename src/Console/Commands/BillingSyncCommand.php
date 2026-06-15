<?php

declare(strict_types=1);

namespace Laracaise\Billing\Console\Commands;

use Illuminate\Console\Command;
use Laracaise\Billing\Enums\BillingInterval;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\PlanFeature;

final class BillingSyncCommand extends Command
{
    protected $signature = 'billing:sync {--prune : Delete configured plan features that are no longer present}';

    protected $description = 'Sync billing plans and features from configuration.';

    public function handle(): int
    {
        $plans = config('laracaise-billing.plans', []);

        if (! is_array($plans) || $plans === []) {
            $this->warn('No plans configured at laracaise-billing.plans.');

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($plans as $slug => $definition) {
            if (! is_string($slug) || ! is_array($definition)) {
                continue;
            }

            $plan = Plan::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => (string) ($definition['name'] ?? str($slug)->headline()),
                    'description' => $definition['description'] ?? null,
                    'amount' => (int) ($definition['amount'] ?? 0),
                    'currency' => (string) ($definition['currency'] ?? config('laracaise-billing.currency', 'ZAR')),
                    'interval' => $this->interval((string) ($definition['interval'] ?? BillingInterval::Monthly->value)),
                    'interval_count' => (int) ($definition['interval_count'] ?? 1),
                    'trial_days' => (int) ($definition['trial_days'] ?? 0),
                    'is_active' => (bool) ($definition['is_active'] ?? true),
                    'sort_order' => (int) ($definition['sort_order'] ?? 0),
                    'metadata' => isset($definition['metadata']) && is_array($definition['metadata'])
                        ? $definition['metadata']
                        : null,
                ],
            );

            $this->syncFeatures($plan, is_array($definition['features'] ?? null) ? $definition['features'] : []);
            $count++;
        }

        $this->info("Synced {$count} billing plan(s).");

        return self::SUCCESS;
    }

    private function interval(string $value): BillingInterval
    {
        return BillingInterval::tryFrom($value) ?? BillingInterval::Monthly;
    }

    /**
     * @param  array<string,mixed>  $features
     */
    private function syncFeatures(Plan $plan, array $features): void
    {
        $synced = [];

        foreach ($features as $feature => $definition) {
            if (! is_string($feature)) {
                continue;
            }

            [$value, $resettable] = $this->featureDefinition($definition);

            PlanFeature::query()->updateOrCreate(
                [
                    'plan_id' => $plan->id,
                    'feature' => $feature,
                ],
                [
                    'value' => $value,
                    'resettable' => $resettable,
                ],
            );

            $synced[] = $feature;
        }

        if ((bool) $this->option('prune') && $synced !== []) {
            $plan->features()->whereNotIn('feature', $synced)->delete();
        }
    }

    /**
     * @return array{0: string|null, 1: bool}
     */
    private function featureDefinition(mixed $definition): array
    {
        if (is_array($definition)) {
            $value = $definition['value'] ?? null;

            return [
                $value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value),
                (bool) ($definition['resettable'] ?? true),
            ];
        }

        return [
            $definition === null ? null : (is_bool($definition) ? ($definition ? 'true' : 'false') : (string) $definition),
            true,
        ];
    }
}
