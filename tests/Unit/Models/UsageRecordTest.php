<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Models\UsageRecord;

it('creates the billing_usage_records table', function () {
    expect(Schema::hasTable('billing_usage_records'))->toBeTrue();
});

it('has no updated_at column', function () {
    expect(Schema::hasColumn('billing_usage_records', 'updated_at'))->toBeFalse();
});

it('has a created_at column', function () {
    expect(Schema::hasColumn('billing_usage_records', 'created_at'))->toBeTrue();
});

it('can create a usage record using the factory', function () {
    $record = UsageRecord::factory()->create();

    expect($record->id)->toBeString()
        ->and($record->quantity)->toBeInt();
});

it('sets created_at automatically on creation', function () {
    $record = UsageRecord::factory()->create();

    expect($record->created_at)->not->toBeNull();
});

it('sets recorded_at automatically on creation', function () {
    $record = UsageRecord::factory()->create();

    expect($record->recorded_at)->not->toBeNull();
});

it('does not have an updated_at property', function () {
    $record = UsageRecord::factory()->create();

    expect(isset($record->updated_at))->toBeFalse();
});

it('belongs to a subscription', function () {
    $sub = Subscription::factory()->create();
    $record = UsageRecord::factory()->create(['subscription_id' => $sub->id]);

    expect($record->subscription->id)->toBe($sub->id);
});

it('isIncrement returns true for positive quantities', function () {
    $record = UsageRecord::factory()->create(['quantity' => 10]);

    expect($record->isIncrement())->toBeTrue()
        ->and($record->isDecrement())->toBeFalse();
});

it('isDecrement returns true for negative quantities', function () {
    $record = UsageRecord::factory()->decrement(5)->create();

    expect($record->isDecrement())->toBeTrue()
        ->and($record->isIncrement())->toBeFalse()
        ->and($record->quantity)->toBe(-5);
});

it('forFeature scope filters by feature key', function () {
    UsageRecord::factory()->forFeature('api_calls')->create();
    UsageRecord::factory()->forFeature('seats')->create();

    expect(UsageRecord::forFeature('api_calls')->count())->toBe(1);
});

it('inPeriod scope returns records within the date range', function () {
    $start = now()->subDays(30);
    $end = now()->subDays(10);

    UsageRecord::factory()->recordedAt(now()->subDays(20))->create(); // in range
    UsageRecord::factory()->recordedAt(now()->subDays(5))->create();  // out of range

    expect(UsageRecord::inPeriod($start, $end)->count())->toBe(1);
});

it('can sum usage for a feature in a period', function () {
    $sub = Subscription::factory()->create();
    $start = now()->startOfMonth();
    $end = now()->endOfMonth();

    UsageRecord::factory()->create(['subscription_id' => $sub->id, 'feature' => 'api_calls', 'quantity' => 100, 'recorded_at' => now()]);
    UsageRecord::factory()->create(['subscription_id' => $sub->id, 'feature' => 'api_calls', 'quantity' => 50, 'recorded_at' => now()]);

    $total = $sub->usageRecords()
        ->forFeature('api_calls')
        ->inPeriod($start, $end)
        ->sum('quantity');

    expect($total)->toBe(150);
});
