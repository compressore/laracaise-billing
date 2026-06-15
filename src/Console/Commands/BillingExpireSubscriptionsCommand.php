<?php

declare(strict_types=1);

namespace Laracaise\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laracaise\Billing\Enums\BillingInterval;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Events\SubscriptionExpired;
use Laracaise\Billing\Models\Subscription;

final class BillingExpireSubscriptionsCommand extends Command
{
    protected $signature = 'billing:expire-subscriptions {--before= : Expire subscriptions ending before this date/time}';

    protected $description = 'Expire cancelled subscriptions whose grace period has elapsed.';

    public function handle(): int
    {
        $cutoff = $this->cutoff();
        $count = 0;

        Subscription::query()
            ->with('plan')
            ->where('status', '!=', SubscriptionStatus::Expired->value)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $cutoff)
            ->chunkById(100, function ($subscriptions) use (&$count): void {
                foreach ($subscriptions as $subscription) {
                    if (! $this->shouldExpire($subscription)) {
                        continue;
                    }

                    $subscription->update([
                        'status' => SubscriptionStatus::Expired,
                        'cancelled_at' => $subscription->cancelled_at ?? now(),
                    ]);

                    event(new SubscriptionExpired($subscription));
                    $count++;
                }
            });

        $this->info("Expired {$count} subscription(s).");

        return self::SUCCESS;
    }

    private function cutoff(): Carbon
    {
        $before = $this->option('before');

        return is_string($before) && $before !== '' ? Carbon::parse($before) : now();
    }

    private function shouldExpire(Subscription $subscription): bool
    {
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            return true;
        }

        return $subscription->plan?->interval === BillingInterval::Once
            && in_array($subscription->status, [
                SubscriptionStatus::Active,
                SubscriptionStatus::Trialing,
                SubscriptionStatus::PastDue,
            ], true);
    }
}
