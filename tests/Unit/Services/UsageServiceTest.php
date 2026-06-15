<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Laracaise\Billing\Events\UsageLimitReached;
use Laracaise\Billing\Exceptions\FeatureNotAvailableException;
use Laracaise\Billing\Exceptions\UsageLimitExceededException;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\PlanFeature;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Models\UsageRecord;
use Laracaise\Billing\Services\UsageService;

beforeEach(function () {
    $this->service = app(UsageService::class);

    $this->plan = Plan::factory()->create();
    $this->sub = Subscription::factory()->create([
        'plan_id' => $this->plan->id,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);
});

// ---------------------------------------------------------------------------
// Helper: add a feature to the plan
// ---------------------------------------------------------------------------

function addUsageFeature(Plan $plan, string $feature, ?string $value, bool $resettable = true): PlanFeature
{
    return PlanFeature::factory()->create([
        'plan_id' => $plan->id,
        'feature' => $feature,
        'value' => $value,
        'resettable' => $resettable,
    ]);
}

// ---------------------------------------------------------------------------
// getUsageInPeriod()
// ---------------------------------------------------------------------------

it('returns 0 when no usage records exist', function () {
    addUsageFeature($this->plan, 'api_calls', '1000');

    expect($this->service->getUsageInPeriod($this->sub, 'api_calls'))->toBe(0);
});

it('sums positive usage records within the period', function () {
    addUsageFeature($this->plan, 'api_calls', '1000');

    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 50, 'recorded_at' => now()]);
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 30, 'recorded_at' => now()]);

    expect($this->service->getUsageInPeriod($this->sub, 'api_calls'))->toBe(80);
});

it('excludes usage records outside the current period', function () {
    addUsageFeature($this->plan, 'api_calls', '1000');

    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 100, 'recorded_at' => now()->subMonths(2)]);
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 10, 'recorded_at' => now()]);

    expect($this->service->getUsageInPeriod($this->sub, 'api_calls'))->toBe(10);
});

it('accounts for negative correction records in the sum', function () {
    addUsageFeature($this->plan, 'api_calls', '1000');

    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 80, 'recorded_at' => now()]);
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => -80, 'recorded_at' => now()]);

    expect($this->service->getUsageInPeriod($this->sub, 'api_calls'))->toBe(0);
});

// ---------------------------------------------------------------------------
// canUse()
// ---------------------------------------------------------------------------

it('returns false for a feature that does not exist on the plan', function () {
    expect($this->service->canUse($this->sub, 'nonexistent'))->toBeFalse();
});

it('returns false for a flag feature', function () {
    addUsageFeature($this->plan, 'reports', 'true', false);

    expect($this->service->canUse($this->sub, 'reports'))->toBeFalse();
});

it('returns true for an unlimited feature', function () {
    addUsageFeature($this->plan, 'storage', null);

    expect($this->service->canUse($this->sub, 'storage'))->toBeTrue();
});

it('returns true when usage is under the limit', function () {
    addUsageFeature($this->plan, 'api_calls', '100');
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 50, 'recorded_at' => now()]);

    expect($this->service->canUse($this->sub, 'api_calls', 1))->toBeTrue();
});

it('returns true when usage would exactly reach the limit', function () {
    addUsageFeature($this->plan, 'api_calls', '100');
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 99, 'recorded_at' => now()]);

    expect($this->service->canUse($this->sub, 'api_calls', 1))->toBeTrue();
});

it('returns false when usage would exceed the limit', function () {
    addUsageFeature($this->plan, 'api_calls', '100');
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 100, 'recorded_at' => now()]);

    expect($this->service->canUse($this->sub, 'api_calls', 1))->toBeFalse();
});

// ---------------------------------------------------------------------------
// consume()
// ---------------------------------------------------------------------------

it('throws FeatureNotAvailableException for a feature not on the plan', function () {
    $this->service->consume($this->sub, 'nonexistent');
})->throws(FeatureNotAvailableException::class);

it('throws FeatureNotAvailableException for a flag feature', function () {
    addUsageFeature($this->plan, 'reports', 'true', false);

    $this->service->consume($this->sub, 'reports');
})->throws(FeatureNotAvailableException::class);

it('inserts a usage record for an unlimited feature without a limit check', function () {
    addUsageFeature($this->plan, 'storage', null);

    $record = $this->service->consume($this->sub, 'storage', 512);

    expect($record)->toBeInstanceOf(UsageRecord::class)
        ->and($record->quantity)->toBe(512)
        ->and($record->feature)->toBe('storage');
});

it('inserts a usage record when within the limit', function () {
    addUsageFeature($this->plan, 'api_calls', '100');

    $record = $this->service->consume($this->sub, 'api_calls', 10);

    expect($record->quantity)->toBe(10)
        ->and(UsageRecord::count())->toBe(1);
});

it('throws UsageLimitExceededException when the limit would be exceeded', function () {
    Event::fake([UsageLimitReached::class]);
    addUsageFeature($this->plan, 'api_calls', '10');
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 10, 'recorded_at' => now()]);

    try {
        $this->service->consume($this->sub, 'api_calls', 1);
    } finally {
        Event::assertDispatched(UsageLimitReached::class);
    }
})->throws(UsageLimitExceededException::class);

it('does not insert a record when the limit would be exceeded', function () {
    addUsageFeature($this->plan, 'api_calls', '10');
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 10, 'recorded_at' => now()]);

    try {
        $this->service->consume($this->sub, 'api_calls', 1);
    } catch (UsageLimitExceededException) {
        // expected
    }

    expect(UsageRecord::where('feature', 'api_calls')->count())->toBe(1);
});

it('exception carries correct metadata', function () {
    addUsageFeature($this->plan, 'api_calls', '10');
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 8, 'recorded_at' => now()]);

    try {
        $this->service->consume($this->sub, 'api_calls', 5);
        fail('Expected UsageLimitExceededException');
    } catch (UsageLimitExceededException $e) {
        expect($e->feature)->toBe('api_calls')
            ->and($e->limit)->toBe(10)
            ->and($e->used)->toBe(8)
            ->and($e->requested)->toBe(5);
    }
});

// ---------------------------------------------------------------------------
// remaining()
// ---------------------------------------------------------------------------

it('remaining() throws FeatureNotAvailableException for a feature not on the plan', function () {
    $this->service->remaining($this->sub, 'nonexistent');
})->throws(FeatureNotAvailableException::class);

it('returns null for an unlimited feature', function () {
    addUsageFeature($this->plan, 'storage', null);

    expect($this->service->remaining($this->sub, 'storage'))->toBeNull();
});

it('returns null for a flag feature', function () {
    addUsageFeature($this->plan, 'reports', 'true', false);

    expect($this->service->remaining($this->sub, 'reports'))->toBeNull();
});

it('returns remaining units for a limited feature', function () {
    addUsageFeature($this->plan, 'api_calls', '100');
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 60, 'recorded_at' => now()]);

    expect($this->service->remaining($this->sub, 'api_calls'))->toBe(40);
});

it('returns 0 when fully consumed, not a negative number', function () {
    addUsageFeature($this->plan, 'api_calls', '10');
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 10, 'recorded_at' => now()]);

    expect($this->service->remaining($this->sub, 'api_calls'))->toBe(0);
});

// ---------------------------------------------------------------------------
// resetUsage()
// ---------------------------------------------------------------------------

it('inserts a negative correction record to zero out usage', function () {
    addUsageFeature($this->plan, 'api_calls', '100');
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 70, 'recorded_at' => now()]);

    $this->service->resetUsage($this->sub, 'api_calls');

    expect($this->service->getUsageInPeriod($this->sub, 'api_calls'))->toBe(0)
        ->and(UsageRecord::where('feature', 'api_calls')->count())->toBe(2);
});

it('does not insert a correction record when usage is already 0', function () {
    addUsageFeature($this->plan, 'api_calls', '100');

    $this->service->resetUsage($this->sub, 'api_calls');

    expect(UsageRecord::count())->toBe(0);
});

it('resets all resettable features when no feature is specified', function () {
    addUsageFeature($this->plan, 'api_calls', '100', true);
    addUsageFeature($this->plan, 'exports', '50', true);
    addUsageFeature($this->plan, 'storage', null, false);

    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'api_calls', 'quantity' => 80, 'recorded_at' => now()]);
    UsageRecord::factory()->create(['subscription_id' => $this->sub->id, 'feature' => 'exports', 'quantity' => 30, 'recorded_at' => now()]);

    $this->service->resetUsage($this->sub);

    expect($this->service->getUsageInPeriod($this->sub, 'api_calls'))->toBe(0)
        ->and($this->service->getUsageInPeriod($this->sub, 'exports'))->toBe(0);
});
