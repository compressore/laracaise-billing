<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Laracaise\Billing\Enums\PaymentStatus;
use Laracaise\Billing\Enums\PaymentType;
use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Tests\Fixtures\BillableModel;

it('creates the billing_payments table', function () {
    expect(Schema::hasTable('billing_payments'))->toBeTrue();
});

it('has the expected columns', function () {
    foreach (['subscriptionable_type', 'subscriptionable_id', 'provider', 'provider_reference', 'paid_at'] as $column) {
        expect(Schema::hasColumn('billing_payments', $column))->toBeTrue();
    }
});

it('can create a payment using the factory', function () {
    $payment = Payment::factory()->create();

    expect($payment->id)->toBeString()
        ->and($payment->amount)->toBeInt()
        ->and($payment->status)->toBe(PaymentStatus::Succeeded);
});

it('casts status to PaymentStatus enum', function () {
    expect(Payment::factory()->succeeded()->create()->status)->toBe(PaymentStatus::Succeeded);
    expect(Payment::factory()->failed()->create()->status)->toBe(PaymentStatus::Failed);
    expect(Payment::factory()->pending()->create()->status)->toBe(PaymentStatus::Pending);
});

it('casts type to PaymentType enum', function () {
    expect(Payment::factory()->create()->type)->toBe(PaymentType::Charge);
    expect(Payment::factory()->refund()->create()->type)->toBe(PaymentType::Refund);
});

it('belongs to a subscription', function () {
    $sub     = Subscription::factory()->create();
    $payment = Payment::factory()->create(['subscription_id' => $sub->id]);

    expect($payment->subscription->id)->toBe($sub->id);
});

it('morphs to the subscriptionable owner', function () {
    $owner   = BillableModel::create(['name' => 'Acme']);
    $sub     = Subscription::factory()->forOwner($owner)->create();
    $payment = Payment::factory()->forOwner($owner)->create(['subscription_id' => $sub->id]);

    expect($payment->subscriptionable->id)->toBe($owner->id);
});

it('can exist without a subscription (standalone payment)', function () {
    $owner   = BillableModel::create(['name' => 'Standalone']);
    $payment = Payment::factory()->forOwner($owner)->create(['subscription_id' => null]);

    expect($payment->subscription)->toBeNull();
});

it('isSucceeded returns true only for succeeded status', function () {
    expect(Payment::factory()->succeeded()->create()->isSucceeded())->toBeTrue()
        ->and(Payment::factory()->failed()->create()->isSucceeded())->toBeFalse();
});

it('isPending returns true only for pending status', function () {
    expect(Payment::factory()->pending()->create()->isPending())->toBeTrue()
        ->and(Payment::factory()->succeeded()->create()->isPending())->toBeFalse();
});

it('isFailed returns true only for failed status', function () {
    expect(Payment::factory()->failed()->create()->isFailed())->toBeTrue()
        ->and(Payment::factory()->succeeded()->create()->isFailed())->toBeFalse();
});

it('isRefund returns true for refund type', function () {
    expect(Payment::factory()->refund()->create()->isRefund())->toBeTrue()
        ->and(Payment::factory()->create()->isRefund())->toBeFalse();
});

it('succeeded scope filters correctly', function () {
    Payment::factory()->succeeded()->create();
    Payment::factory()->failed()->create();
    Payment::factory()->pending()->create();

    expect(Payment::succeeded()->count())->toBe(1);
});

it('failed scope filters correctly', function () {
    Payment::factory()->failed()->create();
    Payment::factory()->succeeded()->create();

    expect(Payment::failed()->count())->toBe(1);
});

it('charges scope excludes refunds', function () {
    Payment::factory()->create();
    Payment::factory()->refund()->create();

    expect(Payment::charges()->count())->toBe(1)
        ->and(Payment::refunds()->count())->toBe(1);
});

it('forProvider scope filters by provider name', function () {
    Payment::factory()->withProvider('paystack')->create();
    Payment::factory()->withProvider('manual')->create();

    expect(Payment::forProvider('paystack')->count())->toBe(1);
});

it('forOwner scope returns only that owner\'s payments', function () {
    $a = BillableModel::create(['name' => 'A']);
    $b = BillableModel::create(['name' => 'B']);

    Payment::factory()->forOwner($a)->create(['subscription_id' => null]);
    Payment::factory()->forOwner($a)->create(['subscription_id' => null]);
    Payment::factory()->forOwner($b)->create(['subscription_id' => null]);

    expect(Payment::forOwner($a)->count())->toBe(2)
        ->and(Payment::forOwner($b)->count())->toBe(1);
});
