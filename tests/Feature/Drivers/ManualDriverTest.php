<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Laracaise\Billing\Drivers\ManualDriver;
use Laracaise\Billing\Enums\BillingInterval;
use Laracaise\Billing\Enums\PaymentStatus;
use Laracaise\Billing\Enums\PaymentType;
use Laracaise\Billing\Enums\SubscriptionStatus;
use Laracaise\Billing\Events\ManualPaymentRecorded;
use Laracaise\Billing\Events\SubscriptionActivated;
use Laracaise\Billing\Events\SubscriptionCancelled;
use Laracaise\Billing\Events\SubscriptionCreated;
use Laracaise\Billing\Events\SubscriptionExpired;
use Laracaise\Billing\Events\SubscriptionExtended;
use Laracaise\Billing\Events\SubscriptionRenewed;
use Laracaise\Billing\Events\SubscriptionResumed;
use Laracaise\Billing\Events\SubscriptionSuspended;
use Laracaise\Billing\Exceptions\InvalidTransitionException;
use Laracaise\Billing\Models\Payment;
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\Subscription;
use Laracaise\Billing\Tests\Fixtures\BillableModel;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function driver(): ManualDriver
{
    return new ManualDriver;
}

function billable(): BillableModel
{
    return BillableModel::create(['name' => 'Acme']);
}

function monthlyPlan(): Plan
{
    return Plan::factory()->create([
        'interval' => BillingInterval::Monthly,
        'interval_count' => 1,
        'trial_days' => 0,
        'currency' => 'ZAR',
    ]);
}

function weeklyPlan(): Plan
{
    return Plan::factory()->create([
        'interval' => BillingInterval::Weekly,
        'interval_count' => 1,
        'trial_days' => 0,
        'currency' => 'ZAR',
    ]);
}

function yearlyPlan(): Plan
{
    return Plan::factory()->create([
        'interval' => BillingInterval::Yearly,
        'interval_count' => 1,
        'trial_days' => 0,
        'currency' => 'ZAR',
    ]);
}

function oncePlan(): Plan
{
    return Plan::factory()->create([
        'interval' => BillingInterval::Once,
        'interval_count' => 1,
        'trial_days' => 0,
        'currency' => 'ZAR',
    ]);
}

function activeSubscription(BillableModel $owner, Plan $plan): Subscription
{
    return Subscription::factory()->forOwner($owner)->create([
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::Active,
        'provider' => 'manual',
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);
}

// ---------------------------------------------------------------------------
// name()
// ---------------------------------------------------------------------------

it('name() returns manual', function () {
    expect(driver()->name())->toBe('manual');
});

// ---------------------------------------------------------------------------
// createSubscription
// ---------------------------------------------------------------------------

it('createSubscription creates a Pending subscription linked to the billable', function () {
    Event::fake();

    $owner = billable();
    $plan = monthlyPlan();

    $sub = driver()->createSubscription($owner, $plan);

    expect($sub)->toBeInstanceOf(Subscription::class)
        ->and($sub->status)->toBe(SubscriptionStatus::Pending)
        ->and($sub->provider)->toBe('manual')
        ->and($sub->plan_id)->toBe($plan->id)
        ->and($sub->name)->toBe('default')
        ->and($sub->quantity)->toBe(1)
        ->and($sub->current_period_start)->toBeNull()
        ->and($sub->current_period_end)->toBeNull()
        ->and($sub->trial_ends_at)->toBeNull();
});

it('createSubscription accepts a custom subscription name', function () {
    Event::fake();

    $owner = billable();
    $sub = driver()->createSubscription($owner, monthlyPlan(), 'addon');

    expect($sub->name)->toBe('addon');
});

it('createSubscription accepts a custom quantity', function () {
    Event::fake();

    $owner = billable();
    $sub = driver()->createSubscription($owner, monthlyPlan(), 'default', ['quantity' => 5]);

    expect($sub->quantity)->toBe(5);
});

it('createSubscription creates Trialing when the plan has trial_days', function () {
    Event::fake();

    $plan = Plan::factory()->create(['trial_days' => 14, 'interval' => BillingInterval::Monthly]);
    $sub = driver()->createSubscription(billable(), $plan);

    expect($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and($sub->trial_ends_at)->not->toBeNull()
        ->and(now()->diffInDays($sub->trial_ends_at))->toBeBetween(13, 14);
});

it('createSubscription respects trial_days override of 0 (no trial)', function () {
    Event::fake();

    $plan = Plan::factory()->create(['trial_days' => 14, 'interval' => BillingInterval::Monthly]);
    $sub = driver()->createSubscription(billable(), $plan, 'default', ['trial_days' => 0]);

    expect($sub->status)->toBe(SubscriptionStatus::Pending)
        ->and($sub->trial_ends_at)->toBeNull();
});

it('createSubscription stores metadata on the subscription', function () {
    Event::fake();

    $sub = driver()->createSubscription(billable(), monthlyPlan(), 'default', [
        'metadata' => ['po_number' => 'PO-001'],
    ]);

    expect($sub->metadata)->toBe(['po_number' => 'PO-001']);
});

it('createSubscription fires SubscriptionCreated', function () {
    Event::fake();

    $sub = driver()->createSubscription(billable(), monthlyPlan());

    Event::assertDispatched(SubscriptionCreated::class, fn ($e) => $e->subscription->id === $sub->id);
});

// ---------------------------------------------------------------------------
// activateSubscription
// ---------------------------------------------------------------------------

it('activateSubscription transitions Pending to Active', function () {
    Event::fake();

    $owner = billable();
    $sub = driver()->createSubscription($owner, monthlyPlan());

    driver()->activateSubscription($sub);

    expect($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->current_period_start)->not->toBeNull()
        ->and($sub->current_period_end)->not->toBeNull()
        ->and($sub->trial_ends_at)->toBeNull();
});

it('activateSubscription transitions Trialing to Active and clears trial_ends_at', function () {
    Event::fake();

    $plan = Plan::factory()->create(['trial_days' => 7, 'interval' => BillingInterval::Monthly]);
    $sub = driver()->createSubscription(billable(), $plan);

    expect($sub->status)->toBe(SubscriptionStatus::Trialing);

    driver()->activateSubscription($sub);

    expect($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->trial_ends_at)->toBeNull();
});

it('activateSubscription sets period based on plan interval (monthly = ~1 month)', function () {
    Event::fake();

    $sub = driver()->createSubscription(billable(), monthlyPlan());
    driver()->activateSubscription($sub);

    $diff = $sub->current_period_start->diffInDays($sub->current_period_end);
    expect($diff)->toBeGreaterThanOrEqual(28)->toBeLessThanOrEqual(31);
});

it('activateSubscription accepts a custom activation date', function () {
    Event::fake();

    $activatedAt = now()->subDays(5);
    $sub = driver()->createSubscription(billable(), monthlyPlan());

    driver()->activateSubscription($sub, $activatedAt);

    expect($sub->current_period_start->format('Y-m-d'))->toBe($activatedAt->format('Y-m-d'));
});

it('activateSubscription throws when subscription is already Active', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, monthlyPlan());

    driver()->activateSubscription($sub);
})->throws(InvalidTransitionException::class);

it('activateSubscription throws when subscription is Cancelled', function () {
    Event::fake();

    $sub = Subscription::factory()->forOwner(billable())->cancelled()->create([
        'plan_id' => monthlyPlan()->id,
    ]);

    driver()->activateSubscription($sub);
})->throws(InvalidTransitionException::class);

it('activateSubscription fires SubscriptionActivated', function () {
    Event::fake();

    $sub = driver()->createSubscription(billable(), monthlyPlan());
    driver()->activateSubscription($sub);

    Event::assertDispatched(SubscriptionActivated::class, fn ($e) => $e->subscription->id === $sub->id);
});

// ---------------------------------------------------------------------------
// renewSubscription
// ---------------------------------------------------------------------------

it('renewSubscription advances the billing period for a monthly plan', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, monthlyPlan());
    $oldEnd = $sub->current_period_end->copy();

    driver()->renewSubscription($sub);

    expect($sub->current_period_start->format('Y-m-d'))->toBe($oldEnd->format('Y-m-d'))
        ->and($sub->current_period_end->gt($oldEnd))->toBeTrue()
        ->and($sub->status)->toBe(SubscriptionStatus::Active);
});

it('renewSubscription advances the billing period for a weekly plan', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, weeklyPlan());
    $oldEnd = $sub->current_period_end->copy();

    driver()->renewSubscription($sub);

    $diff = $sub->current_period_start->diffInDays($sub->current_period_end);
    expect($diff)->toEqual(7)
        ->and($sub->current_period_start->format('Y-m-d'))->toBe($oldEnd->format('Y-m-d'));
});

it('renewSubscription advances the billing period for a yearly plan', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, yearlyPlan());
    $oldEnd = $sub->current_period_end->copy();

    driver()->renewSubscription($sub);

    $diff = $sub->current_period_start->diffInYears($sub->current_period_end);
    expect($diff)->toEqual(1)
        ->and($sub->current_period_start->format('Y-m-d'))->toBe($oldEnd->format('Y-m-d'));
});

it('renewSubscription accepts an explicit period start date', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());
    $start = now()->addDays(3);

    driver()->renewSubscription($sub, $start);

    expect($sub->current_period_start->format('Y-m-d'))->toBe($start->format('Y-m-d'));
});

it('renewSubscription transitions PastDue back to Active', function () {
    Event::fake();

    $plan = monthlyPlan();
    $sub = Subscription::factory()->forOwner(billable())->pastDue()->create([
        'plan_id' => $plan->id,
        'provider' => 'manual',
        'current_period_end' => now()->endOfMonth(),
    ]);

    driver()->renewSubscription($sub);

    expect($sub->status)->toBe(SubscriptionStatus::Active);
});

it('renewSubscription throws for a Once plan', function () {
    Event::fake();

    $sub = activeSubscription(billable(), oncePlan());

    driver()->renewSubscription($sub);
})->throws(InvalidTransitionException::class);

it('renewSubscription throws when subscription is Cancelled', function () {
    Event::fake();

    $sub = Subscription::factory()->forOwner(billable())->cancelled()->create([
        'plan_id' => monthlyPlan()->id,
    ]);

    driver()->renewSubscription($sub);
})->throws(InvalidTransitionException::class);

it('renewSubscription fires SubscriptionRenewed', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());
    driver()->renewSubscription($sub);

    Event::assertDispatched(SubscriptionRenewed::class, fn ($e) => $e->subscription->id === $sub->id);
});

// ---------------------------------------------------------------------------
// extendSubscription
// ---------------------------------------------------------------------------

it('extendSubscription adds the given days to current_period_end', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, monthlyPlan());
    $oldEnd = $sub->current_period_end->copy();

    driver()->extendSubscription($sub, 14);

    expect($oldEnd->diffInDays($sub->current_period_end))->toEqual(14);
});

it('extendSubscription works on a PastDue subscription', function () {
    Event::fake();

    $plan = monthlyPlan();
    $sub = Subscription::factory()->forOwner(billable())->pastDue()->create([
        'plan_id' => $plan->id,
        'current_period_end' => now()->addDays(5),
    ]);

    driver()->extendSubscription($sub, 10);

    expect(now()->diffInDays($sub->current_period_end))->toBeBetween(14, 16);
});

it('extendSubscription throws for a Cancelled subscription', function () {
    Event::fake();

    $sub = Subscription::factory()->forOwner(billable())->cancelled()->create([
        'plan_id' => monthlyPlan()->id,
    ]);

    driver()->extendSubscription($sub, 7);
})->throws(InvalidTransitionException::class);

it('extendSubscription throws for an Expired subscription', function () {
    Event::fake();

    $sub = Subscription::factory()->forOwner(billable())->expired()->create([
        'plan_id' => monthlyPlan()->id,
    ]);

    driver()->extendSubscription($sub, 7);
})->throws(InvalidTransitionException::class);

it('extendSubscription fires SubscriptionExtended', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());
    driver()->extendSubscription($sub, 7);

    Event::assertDispatched(SubscriptionExtended::class, fn ($e) => $e->subscription->id === $sub->id);
});

// ---------------------------------------------------------------------------
// suspendSubscription
// ---------------------------------------------------------------------------

it('suspendSubscription transitions Active to PastDue', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());

    driver()->suspendSubscription($sub);

    expect($sub->status)->toBe(SubscriptionStatus::PastDue);
});

it('suspendSubscription throws when subscription is already PastDue', function () {
    Event::fake();

    $plan = monthlyPlan();
    $sub = Subscription::factory()->forOwner(billable())->pastDue()->create([
        'plan_id' => $plan->id,
    ]);

    driver()->suspendSubscription($sub);
})->throws(InvalidTransitionException::class);

it('suspendSubscription throws when subscription is Cancelled', function () {
    Event::fake();

    $sub = Subscription::factory()->forOwner(billable())->cancelled()->create([
        'plan_id' => monthlyPlan()->id,
    ]);

    driver()->suspendSubscription($sub);
})->throws(InvalidTransitionException::class);

it('suspendSubscription fires SubscriptionSuspended', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());
    driver()->suspendSubscription($sub);

    Event::assertDispatched(SubscriptionSuspended::class, fn ($e) => $e->subscription->id === $sub->id);
});

// ---------------------------------------------------------------------------
// resumeSubscription
// ---------------------------------------------------------------------------

it('resumeSubscription transitions PastDue to Active', function () {
    Event::fake();

    $plan = monthlyPlan();
    $sub = Subscription::factory()->forOwner(billable())->pastDue()->create([
        'plan_id' => $plan->id,
    ]);

    driver()->resumeSubscription($sub);

    expect($sub->status)->toBe(SubscriptionStatus::Active);
});

it('resumeSubscription throws when subscription is Active', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());

    driver()->resumeSubscription($sub);
})->throws(InvalidTransitionException::class);

it('resumeSubscription throws when subscription is Cancelled', function () {
    Event::fake();

    $sub = Subscription::factory()->forOwner(billable())->cancelled()->create([
        'plan_id' => monthlyPlan()->id,
    ]);

    driver()->resumeSubscription($sub);
})->throws(InvalidTransitionException::class);

it('resumeSubscription fires SubscriptionResumed', function () {
    Event::fake();

    $plan = monthlyPlan();
    $sub = Subscription::factory()->forOwner(billable())->pastDue()->create([
        'plan_id' => $plan->id,
    ]);

    driver()->resumeSubscription($sub);

    Event::assertDispatched(SubscriptionResumed::class, fn ($e) => $e->subscription->id === $sub->id);
});

// ---------------------------------------------------------------------------
// cancelSubscription
// ---------------------------------------------------------------------------

it('cancelSubscription sets status to Cancelled and records cancelled_at', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());

    driver()->cancelSubscription($sub);

    expect($sub->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($sub->cancelled_at)->not->toBeNull();
});

it('cancelSubscription preserves current_period_end for grace-period access by default', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());
    $oldEnd = $sub->current_period_end->copy();

    driver()->cancelSubscription($sub);

    expect($sub->current_period_end->format('Y-m-d'))->toBe($oldEnd->format('Y-m-d'));
});

it('cancelSubscription immediate sets current_period_end to now', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());

    driver()->cancelSubscription($sub, immediate: true);

    expect($sub->current_period_end->isToday())->toBeTrue();
});

it('cancelSubscription can cancel from PastDue', function () {
    Event::fake();

    $plan = monthlyPlan();
    $sub = Subscription::factory()->forOwner(billable())->pastDue()->create([
        'plan_id' => $plan->id,
    ]);

    driver()->cancelSubscription($sub);

    expect($sub->status)->toBe(SubscriptionStatus::Cancelled);
});

it('cancelSubscription fires SubscriptionCancelled', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());
    driver()->cancelSubscription($sub);

    Event::assertDispatched(SubscriptionCancelled::class, fn ($e) => $e->subscription->id === $sub->id);
});

// ---------------------------------------------------------------------------
// expireSubscription
// ---------------------------------------------------------------------------

it('expireSubscription transitions any status to Expired', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());

    driver()->expireSubscription($sub);

    expect($sub->status)->toBe(SubscriptionStatus::Expired)
        ->and($sub->cancelled_at)->not->toBeNull()
        ->and($sub->current_period_end->isToday())->toBeTrue();
});

it('expireSubscription can expire a Cancelled subscription', function () {
    Event::fake();

    $sub = Subscription::factory()->forOwner(billable())->cancelled()->create([
        'plan_id' => monthlyPlan()->id,
    ]);

    driver()->expireSubscription($sub);

    expect($sub->status)->toBe(SubscriptionStatus::Expired);
});

it('expireSubscription can expire a PastDue subscription', function () {
    Event::fake();

    $plan = monthlyPlan();
    $sub = Subscription::factory()->forOwner(billable())->pastDue()->create([
        'plan_id' => $plan->id,
    ]);

    driver()->expireSubscription($sub);

    expect($sub->status)->toBe(SubscriptionStatus::Expired);
});

it('expireSubscription fires SubscriptionExpired', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());
    driver()->expireSubscription($sub);

    Event::assertDispatched(SubscriptionExpired::class, fn ($e) => $e->subscription->id === $sub->id);
});

// ---------------------------------------------------------------------------
// recordPayment
// ---------------------------------------------------------------------------

it('recordPayment creates a Succeeded Payment with provider manual', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, monthlyPlan());

    $payment = driver()->recordPayment($sub, 9900, 'REF-001');

    expect($payment)->toBeInstanceOf(Payment::class)
        ->and($payment->provider)->toBe('manual')
        ->and($payment->provider_reference)->toBe('REF-001')
        ->and($payment->amount)->toBe(9900)
        ->and($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->type)->toBe(PaymentType::Charge)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->subscription_id)->toBe($sub->id);
});

it('recordPayment uses the plan currency by default', function () {
    Event::fake();

    $plan = Plan::factory()->create(['currency' => 'NGN', 'interval' => BillingInterval::Monthly]);
    $owner = billable();
    $sub = activeSubscription($owner, $plan);

    $payment = driver()->recordPayment($sub, 5000, 'REF-002');

    expect($payment->currency)->toBe('NGN');
});

it('recordPayment accepts a currency override', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());
    $payment = driver()->recordPayment($sub, 5000, 'REF-003', ['currency' => 'USD']);

    expect($payment->currency)->toBe('USD');
});

it('recordPayment stores optional metadata', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());
    $payment = driver()->recordPayment($sub, 5000, 'REF-004', [
        'metadata' => ['notes' => 'Cash received at front desk'],
    ]);

    expect($payment->metadata)->toBe(['notes' => 'Cash received at front desk']);
});

it('recordPayment fires ManualPaymentRecorded', function () {
    Event::fake();

    $sub = activeSubscription(billable(), monthlyPlan());
    $payment = driver()->recordPayment($sub, 9900, 'REF-005');

    Event::assertDispatched(
        ManualPaymentRecorded::class,
        fn ($e) => $e->payment->id === $payment->id && $e->subscription->id === $sub->id
    );
});

// ---------------------------------------------------------------------------
// charge()
// ---------------------------------------------------------------------------

it('charge sets payment status to Pending with provider manual', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, monthlyPlan());

    $payment = Payment::factory()->forOwner($owner)->create([
        'subscription_id' => $sub->id,
        'amount' => 9900,
        'currency' => 'ZAR',
        'status' => PaymentStatus::Pending,
        'type' => PaymentType::Charge,
        'provider' => null,
    ]);

    $result = driver()->charge($payment);

    expect($result->provider)->toBe('manual')
        ->and($result->status)->toBe(PaymentStatus::Pending);
});

// ---------------------------------------------------------------------------
// verifyTransaction()
// ---------------------------------------------------------------------------

it('verifyTransaction marks a pending payment as Succeeded', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, monthlyPlan());

    $payment = Payment::factory()->forOwner($owner)->create([
        'subscription_id' => $sub->id,
        'provider' => 'manual',
        'provider_reference' => 'TXN-999',
        'status' => PaymentStatus::Pending,
        'type' => PaymentType::Charge,
    ]);

    $result = driver()->verifyTransaction('TXN-999');

    expect($result->id)->toBe($payment->id)
        ->and($result->status)->toBe(PaymentStatus::Succeeded)
        ->and($result->paid_at)->not->toBeNull();
});

it('verifyTransaction fires ManualPaymentRecorded when subscription is linked', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, monthlyPlan());

    Payment::factory()->forOwner($owner)->create([
        'subscription_id' => $sub->id,
        'provider' => 'manual',
        'provider_reference' => 'TXN-888',
        'status' => PaymentStatus::Pending,
        'type' => PaymentType::Charge,
    ]);

    driver()->verifyTransaction('TXN-888');

    Event::assertDispatched(
        ManualPaymentRecorded::class,
        fn ($e) => $e->subscription->id === $sub->id
    );
});

it('verifyTransaction throws when the reference does not exist', function () {
    driver()->verifyTransaction('NONEXISTENT');
})->throws(ModelNotFoundException::class);

// ---------------------------------------------------------------------------
// initializeTransaction()
// ---------------------------------------------------------------------------

it('initializeTransaction throws BadMethodCallException', function () {
    $owner = billable();
    $sub = activeSubscription($owner, monthlyPlan());
    $payment = Payment::factory()->forOwner($owner)->create([
        'subscription_id' => $sub->id,
        'status' => PaymentStatus::Pending,
        'type' => PaymentType::Charge,
    ]);

    driver()->initializeTransaction($payment);
})->throws(BadMethodCallException::class);

// ---------------------------------------------------------------------------
// createCustomer()
// ---------------------------------------------------------------------------

it('createCustomer returns the billable model key as a string', function () {
    $owner = billable();

    expect(driver()->createCustomer($owner))->toBe((string) $owner->getKey());
});

// ---------------------------------------------------------------------------
// refund()
// ---------------------------------------------------------------------------

it('refund creates a Refund Payment record for the full amount', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, monthlyPlan());

    $original = Payment::factory()->forOwner($owner)->create([
        'subscription_id' => $sub->id,
        'provider' => 'manual',
        'provider_reference' => 'TXN-100',
        'amount' => 10000,
        'currency' => 'ZAR',
        'status' => PaymentStatus::Succeeded,
        'type' => PaymentType::Charge,
    ]);

    $refund = driver()->refund($original);

    expect($refund->type)->toBe(PaymentType::Refund)
        ->and($refund->amount)->toBe(10000)
        ->and($refund->provider)->toBe('manual')
        ->and($refund->status)->toBe(PaymentStatus::Succeeded)
        ->and($refund->subscription_id)->toBe($sub->id);
});

it('refund creates a partial refund when an amount is provided', function () {
    Event::fake();

    $owner = billable();
    $sub = activeSubscription($owner, monthlyPlan());

    $original = Payment::factory()->forOwner($owner)->create([
        'subscription_id' => $sub->id,
        'provider' => 'manual',
        'amount' => 10000,
        'currency' => 'ZAR',
        'status' => PaymentStatus::Succeeded,
        'type' => PaymentType::Charge,
    ]);

    $refund = driver()->refund($original, 3000);

    expect($refund->amount)->toBe(3000);
});
