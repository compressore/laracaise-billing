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

        $defaultCurrency = $this->strFrom(config('laracaise-billing.currency'), 'ZAR');

        foreach ($plans as $slug => $definition) {
            if (! is_string($slug) || ! is_array($definition)) {
                continue;
            }

            $plan = Plan::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $this->strFrom($definition['name'] ?? null, str($slug)->headline()->value()),
                    'description' => is_string($definition['description'] ?? null) ? $definition['description'] : null,
                    'amount' => $this->intFrom($definition['amount'] ?? null, 0),
                    'currency' => $this->strFrom($definition['currency'] ?? null, $defaultCurrency),
                    'interval' => $this->interval($this->strFrom($definition['interval'] ?? null, BillingInterval::Monthly->value)),
                    'interval_count' => $this->intFrom($definition['interval_count'] ?? null, 1),
                    'trial_days' => $this->intFrom($definition['trial_days'] ?? null, 0),
                    'is_active' => (bool) ($definition['is_active'] ?? true),
                    'sort_order' => $this->intFrom($definition['sort_order'] ?? null, 0),
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
                $value === null ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : null)),
                (bool) ($definition['resettable'] ?? true),
            ];
        }

        return [
            $definition === null ? null : (is_bool($definition) ? ($definition ? 'true' : 'false') : (is_scalar($definition) ? (string) $definition : null)),
            true,
        ];
    }

    private function strFrom(mixed $value, string $default): string
    {
        return is_string($value) ? $value : $default;
    }

    private function intFrom(mixed $value, int $default): int
    {
        return is_int($value) ? $value : $default;
    }
}
