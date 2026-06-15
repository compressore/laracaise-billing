# Public API

This document defines the stable public surface area of `laracaise/billing`. Only what is listed here is considered part of the public contract. Internals may change between minor versions.

---

## Setup — the `Billable` trait

```php
use Laracaise\Billing\Concerns\Billable;

class Team extends Model
{
    use Billable;
}
```

This gives the model a `billing()` method that returns a `BillingContext` scoped to that instance.

---

## `BillingContext` — fluent entry point

All methods below are accessed via `$model->billing()->...`.

---

### Plan & subscription queries

```php
// Is the model on any active subscription?
$team->billing()->subscribed(): bool

// Is the model on a specific plan?
$team->billing()->onPlan('pro'): bool

// Is the model in a trial?
$team->billing()->onTrial(): bool

// Retrieve the active subscription (or named subscription)
$team->billing()->subscription(string $name = 'default'): ?Subscription

// All subscriptions
$team->billing()->subscriptions(): Collection<Subscription>
```

---

### Subscribing

```php
// Subscribe to a plan by slug
$team->billing()->subscribe(
    plan: 'pro',                   // slug or Plan model
    name: 'default',               // subscription name (default: 'default')
    quantity: 1,                   // seats
    trialDays: 14,                 // override plan trial
    paymentMethod: 'tok_xxx',      // gateway token (optional for manual/free plans)
): Subscription

// Subscribe and immediately charge via the configured driver
$team->billing()->subscribe('pro', paymentMethod: 'tok_xxx'): Subscription

// Subscribe to a free/manual plan (no gateway call)
$team->billing()->subscribe('free'): Subscription
```

---

### Changing plans

```php
// Swap to a different plan immediately
$team->billing()->swap(plan: 'enterprise'): Subscription

// Swap at end of current billing period
$team->billing()->swapAndInvoice(plan: 'enterprise'): Subscription
```

---

### Cancellation & resumption

```php
// Cancel at end of current billing period
$team->billing()->cancel(): Subscription

// Cancel immediately
$team->billing()->cancelNow(): Subscription

// Un-cancel a subscription still within its period
$team->billing()->resume(): Subscription
```

---

### Feature & usage

```php
// Does the active plan include a feature (flag or any limit)?
$team->billing()->hasFeature(feature: 'api_calls'): bool

// What is the configured limit for a feature?
$team->billing()->featureLimit(feature: 'api_calls'): int|null  // null = unlimited

// How many units remain this period?
$team->billing()->remainingUsage(feature: 'api_calls'): int|null  // null = unlimited

// Can the model use N more units?
$team->billing()->canUse(feature: 'api_calls', quantity: 100): bool

// Record usage (throws UsageExceededException if limit breached)
$team->billing()->recordUsage(feature: 'api_calls', quantity: 100): UsageRecord

// Record usage without enforcing the limit (useful for async validation)
$team->billing()->recordUsage(feature: 'api_calls', quantity: 100, force: true): UsageRecord

// How much has been used this period?
$team->billing()->usedQuantity(feature: 'api_calls'): int
```

---

### Manual invoicing

```php
$team->billing()
    ->invoice()
    ->add(description: 'Consulting — June 2026', amount: 500_00, quantity: 1)
    ->add(description: 'Hosting', amount: 150_00)
    ->taxRate(15.0)
    ->dueAt(now()->addDays(30))
    ->notes('Payment via EFT to account 62xxxxxxxx')
    ->issue(): Invoice                // saves and fires InvoiceIssued
```

---

### Payment methods

```php
// Store a tokenised payment method
$team->billing()->addPaymentMethod(token: 'tok_xxx', makeDefault: true): PaymentMethod

// Get the default payment method
$team->billing()->defaultPaymentMethod(): ?PaymentMethod

// Remove a stored method
$team->billing()->removePaymentMethod(PaymentMethod $method): void
```

---

### Gateway operations (delegated to the active driver)

```php
// Charge the default payment method for an existing invoice
$team->billing()->charge(Invoice $invoice): Transaction

// Initialise a Paystack popup / redirect (returns a checkout URL or access code)
$team->billing()->initializePayment(Invoice $invoice, array $options = []): PendingTransaction

// Verify a completed payment by gateway reference
Billing::verifyTransaction(reference: 'PAY-xxx'): Transaction

// Refund a transaction (full or partial)
$team->billing()->refund(Transaction $transaction, ?int $amountInCents = null): Transaction
```

---

## Facade

```php
use Laracaise\Billing\Facades\Billing;

Billing::plan('pro')                          // retrieve a Plan model
Billing::plans()                              // all active plans
Billing::driver('paystack')                   // get driver instance explicitly
Billing::verifyTransaction('PAY-xxx')         // gateway verify (driver-agnostic)
Billing::fake()                               // swap all drivers with NullDriver in tests
```

---

## Value objects returned

### `PendingTransaction`

```php
readonly class PendingTransaction
{
    public string $reference;
    public string $checkoutUrl;   // Paystack authorization URL
    public string $accessCode;    // Paystack popup access code
    public array  $raw;           // full gateway response
}
```

### `FeatureCheck` (returned by `canUse()`)

The method returns `bool` directly; `FeatureCheck` is used internally.

---

## Exceptions

| Class | Thrown when |
|---|---|
| `PlanNotFoundException` | A plan slug does not exist or is inactive |
| `AlreadySubscribedException` | Subscribing to a plan the model is already on |
| `NoActiveSubscriptionException` | Calling subscription methods without an active subscription |
| `UsageExceededException` | `recordUsage` would breach the plan limit |
| `PaymentFailedException` | The gateway rejected a charge |
| `InvalidDriverException` | Requesting an unconfigured driver |

All exceptions extend `Laracaise\Billing\Exceptions\BillingException`.

---

## Artisan commands

| Command | Description |
|---|---|
| `billing:plans` | List all plans with feature counts |
| `billing:sync-plans` | Sync plan definitions from config to database |
| `billing:verify {reference}` | Verify a gateway transaction by reference |

---

## Changelog / stability guarantee

- All methods on `BillingContext` and the `Billing` facade are **stable** from v1.0.
- Anything under `Laracaise\Billing\Internal\` is **not** part of the public API.
- Value object properties are read-only; new properties may be added in minor versions.
