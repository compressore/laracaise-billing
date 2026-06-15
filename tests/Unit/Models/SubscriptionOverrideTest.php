<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Models\SubscriptionOverride;

it('creates the billing_subscription_overrides table', function () {
    expect(Schema::hasTable('billing_subscription_overrides'))->toBeTrue();
});

it('can create an override using the factory', function () {
    $override = SubscriptionOverride::factory()->create();

    expect($override->id)->toBeString()
        ->and($override->feature)->toBeString();
});

it('belongs to a subscription', function () {
    $sub = Subscription::factory()->create();
    $override = SubscriptionOverride::factory()->create(['subscription_id' => $sub->id]);

    expect($override->subscription->id)->toBe($sub->id);
});

it('isUnlimited returns true when value is null', function () {
    $unlimited = SubscriptionOverride::factory()->unlimited()->create();
    $limited = SubscriptionOverride::factory()->create(['value' => '500']);

    expect($unlimited->isUnlimited())->toBeTrue()
        ->and($limited->isUnlimited())->toBeFalse();
});

it('limitValue returns the integer value', function () {
    $override = SubscriptionOverride::factory()->create(['value' => '2500']);

    expect($override->limitValue())->toBe(2500);
});

it('limitValue returns null for unlimited overrides', function () {
    expect(SubscriptionOverride::factory()->unlimited()->create()->limitValue())->toBeNull();
});

it('isExpired returns true when expires_at is in the past', function () {
    $expired = SubscriptionOverride::factory()->expired()->create();
    $active = SubscriptionOverride::factory()->expiring(now()->addDays(5))->create();
    $noExpiry = SubscriptionOverride::factory()->create();

    expect($expired->isExpired())->toBeTrue()
        ->and($active->isExpired())->toBeFalse()
        ->and($noExpiry->isExpired())->toBeFalse();
});

it('forFeature scope filters by feature key', function () {
    SubscriptionOverride::factory()->create(['feature' => 'seats']);
    SubscriptionOverride::factory()->create(['feature' => 'api_calls']);

    expect(SubscriptionOverride::forFeature('seats')->count())->toBe(1);
});

it('active scope excludes expired overrides', function () {
    SubscriptionOverride::factory()->create();
    SubscriptionOverride::factory()->expiring(now()->addDays(10))->create();
    SubscriptionOverride::factory()->expired()->create();

    expect(SubscriptionOverride::active()->count())->toBe(2);
});

it('expired scope includes only expired overrides', function () {
    SubscriptionOverride::factory()->create();
    SubscriptionOverride::factory()->expired()->create();

    expect(SubscriptionOverride::expired()->count())->toBe(1);
});

it('cascades delete when subscription is deleted', function () {
    $sub = Subscription::factory()->create();
    SubscriptionOverride::factory()->create(['subscription_id' => $sub->id]);

    $sub->delete();

    expect(SubscriptionOverride::count())->toBe(0);
});
