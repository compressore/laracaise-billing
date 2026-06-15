<?php

declare(strict_types=1);

namespace Laracaise\Billing\Console\Commands;

use Illuminate\Console\Command;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Services\UsageService;

final class BillingResetUsageCommand extends Command
{
    protected $signature = 'billing:reset-usage
        {subscription? : Subscription id to reset}
        {--feature= : Feature slug to reset}
        {--all : Reset all current subscriptions}';

    protected $description = 'Reset append-only usage counters for one subscription or all current subscriptions.';

    public function handle(UsageService $usage): int
    {
        $feature = $this->option('feature');
        $feature = is_string($feature) && $feature !== '' ? $feature : null;
        $subscriptionId = $this->argument('subscription');

        if (is_string($subscriptionId) && $subscriptionId !== '') {
            $subscription = Subscription::query()->find($subscriptionId);

            if ($subscription === null) {
                $this->error("Subscription [{$subscriptionId}] was not found.");

                return self::FAILURE;
            }

            $usage->resetUsage($subscription, $feature);
            $this->info('Reset usage for 1 subscription.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('all')) {
            $this->error('Pass a subscription id or use --all.');

            return self::FAILURE;
        }

        $count = 0;

        Subscription::query()
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->chunkById(100, function ($subscriptions) use ($usage, $feature, &$count): void {
                foreach ($subscriptions as $subscription) {
                    $usage->resetUsage($subscription, $feature);
                    $count++;
                }
            });

        $this->info("Reset usage for {$count} subscription(s).");

        return self::SUCCESS;
    }
}
