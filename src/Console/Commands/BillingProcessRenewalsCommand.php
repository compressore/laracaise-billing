<?php

declare(strict_types=1);

namespace Laracaise\Billing\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Laracaise\Billing\Enums\BillingInterval;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Events\SubscriptionRenewed;
use Laracaise\Billing\Events\TrialEnded;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\Subscription;

final class BillingProcessRenewalsCommand extends Command
{
    protected $signature = 'billing:process-renewals {--before= : Process renewals due before this date/time}';

    protected $description = 'Advance due recurring subscription periods.';

    public function handle(): int
    {
        $cutoff = $this->cutoff();
        $count = 0;

        Subscription::query()
            ->with('plan')
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
                SubscriptionStatus::Trialing->value,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where(function ($query) use ($cutoff): void {
                        $query->whereNotNull('current_period_end')
                            ->where('current_period_end', '<=', $cutoff);
                    })
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->where('status', SubscriptionStatus::Trialing->value)
                            ->whereNotNull('trial_ends_at')
                            ->where('trial_ends_at', '<=', $cutoff);
                    });
            })
            ->chunkById(100, function ($subscriptions) use ($cutoff, &$count): void {
                foreach ($subscriptions as $subscription) {
                    if ($this->endTrialIfDue($subscription, $cutoff)) {
                        $count++;
                    }

                    if ($this->renewIfDue($subscription, $cutoff)) {
                        $count++;
                    }
                }
            });

        $this->info("Processed {$count} renewal action(s).");

        return self::SUCCESS;
    }

    private function cutoff(): Carbon
    {
        $before = $this->option('before');

        return is_string($before) && $before !== '' ? Carbon::parse($before) : now();
    }

    private function endTrialIfDue(Subscription $subscription, Carbon $cutoff): bool
    {
        if (
            $subscription->status !== SubscriptionStatus::Trialing
            || $subscription->trial_ends_at === null
            || $subscription->trial_ends_at->greaterThan($cutoff)
        ) {
            return false;
        }

        $start = $subscription->trial_ends_at->copy();
        $end = $subscription->plan !== null ? $this->periodEnd($subscription->plan, $start) : null;

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'current_period_start' => $subscription->current_period_start ?? $start,
            'current_period_end' => $subscription->current_period_end ?? $end,
        ]);

        $subscription->refresh();
        event(new TrialEnded($subscription));

        return true;
    }

    private function renewIfDue(Subscription $subscription, Carbon $cutoff): bool
    {
        if (
            $subscription->current_period_end === null
            || $subscription->current_period_end->greaterThan($cutoff)
            || $subscription->plan === null
            || ! $subscription->plan->interval->isRecurring()
        ) {
            return false;
        }

        $start = $subscription->current_period_end->copy();

        $subscription->update([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $start,
            'current_period_end' => $this->periodEnd($subscription->plan, $start),
        ]);

        $subscription->refresh();
        event(new SubscriptionRenewed($subscription));

        return true;
    }

    private function periodEnd(Plan $plan, Carbon $start): ?Carbon
    {
        return match ($plan->interval) {
            BillingInterval::Weekly => $start->copy()->addWeeks($plan->interval_count),
            BillingInterval::Monthly => $start->copy()->addMonths($plan->interval_count),
            BillingInterval::Yearly => $start->copy()->addYears($plan->interval_count),
            BillingInterval::Once => null,
        };
    }
}
