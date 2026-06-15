# Architecture

## Layer overview

```
┌──────────────────────────────────────────────────────────┐
│                  Host Application                        │
│  ($user->billing()->subscribe($plan))                    │
└────────────────────────┬─────────────────────────────────┘
                         │
┌────────────────────────▼─────────────────────────────────┐
│              Public API  (BillingManager)                │
│  Facade · Billable trait · fluent builder chain          │
└──────┬────────────────────────────────┬──────────────────┘
       │                                │
┌──────▼──────────┐          ┌──────────▼──────────────────┐
│  Subscription   │          │   Invoice / Manual Billing   │
│  Service        │          │   Service                    │
└──────┬──────────┘          └──────────┬──────────────────┘
       │                                │
┌──────▼────────────────────────────────▼──────────────────┐
│                  Domain Models (Eloquent)                 │
│  Plan · PlanFeature · Subscription · SubscriptionItem    │
│  UsageRecord · Invoice · InvoiceItem · Transaction       │
└────────────────────────┬─────────────────────────────────┘
                         │
┌────────────────────────▼─────────────────────────────────┐
│                  Driver Layer                            │
│  PaymentDriverInterface → PaystackDriver | NullDriver    │
└────────────────────────┬─────────────────────────────────┘
                         │
                  External Gateway API
```

---

## Core components

### `BillingManager`

The central service class, bound as a singleton in the container. Exposes top-level operations:

- `subscribe(Billable $model, Plan $plan, array $options)`
- `cancel(Subscription $subscription, bool $immediately)`
- `swap(Subscription $subscription, Plan $newPlan)`
- `invoice(Billable $model): InvoiceBuilder`
- `driver(string $name): PaymentDriverInterface`

### `Billable` trait

Mixed into any Eloquent model that needs billing. Provides:

- `billing(): BillingContext` — returns a fluent context object scoped to this model
- `subscriptions(): HasMany` — Eloquent relation
- `invoices(): MorphMany` — Eloquent relation

### `BillingContext`

A short-lived value object that wraps a billable model and delegates to `BillingManager`. Allows the ergonomic fluent API without polluting the model itself:

```php
$team->billing()->onPlan('pro')           // bool
$team->billing()->subscribe('pro')        // Subscription
$team->billing()->usage('api_calls')->increment(10)
$team->billing()->invoice()->add('Consulting', 500_00)->issue()
```

### `SubscriptionService`

Handles the state machine for subscriptions:

```
pending → active → past_due → cancelled
                ↘ trialing ↗
```

Transitions fire events. No transition bypasses the service (models do not mutate their own status).

### `UsageService`

Reads the billable's active subscription, looks up the feature limit on the plan, and compares against the current period's `UsageRecord` sum. Returns `bool`, `int` (remaining), or throws `UsageExceededException`.

### `InvoiceService`

Builds, issues, and marks invoices paid. Supports:

- **Auto-invoicing** — triggered by subscription renewal
- **Manual invoicing** — arbitrary line items, no subscription required

### `PaymentDriverInterface`

```php
interface PaymentDriverInterface
{
    public function charge(Invoice $invoice, array $options): Transaction;
    public function initializeTransaction(Invoice $invoice, array $options): PendingTransaction;
    public function verifyTransaction(string $reference): Transaction;
    public function refund(Transaction $transaction, ?int $amountInCents = null): Transaction;
    public function createCustomer(Billable $billable, array $data): string; // returns gateway customer ID
}
```

### `NullDriver`

A no-op driver used in tests and for pure manual billing setups. All methods return fake successful responses.

---

## Service provider bindings

```php
// Singleton
$this->app->singleton(BillingManager::class, fn ($app) => new BillingManager($app));

// Facade accessor
$this->app->alias(BillingManager::class, 'billing');
```

---

## Event catalogue

| Event | Fired when |
|---|---|
| `SubscriptionCreated` | A new subscription becomes active |
| `SubscriptionCancelled` | Cancellation is scheduled or immediate |
| `SubscriptionResumed` | A cancelled-at-period-end sub is un-cancelled |
| `SubscriptionSwapped` | Plan changed mid-cycle |
| `SubscriptionRenewed` | Billing cycle rolls over |
| `SubscriptionPastDue` | Renewal charge fails |
| `InvoiceCreated` | Invoice record is persisted |
| `InvoiceIssued` | Invoice sent to the customer |
| `InvoicePaid` | Payment confirmed |
| `InvoiceVoided` | Invoice cancelled before payment |
| `TransactionSucceeded` | Gateway confirms payment |
| `TransactionFailed` | Gateway rejects payment |
| `TransactionRefunded` | Refund completed |
| `UsageExceeded` | A usage check finds the limit has been hit |

---

## Configuration resolution order

1. `config/laracaise-billing.php` in host app (published)
2. Package default config (merged via `mergeConfigFrom`)
3. Per-subscription overrides (stored on `subscriptions.metadata` JSON column)

---

## Multi-tenancy stance

The package stores `billable_type` and `billable_id` morph columns on every ownership table. The host application is responsible for scoping queries to the correct tenant. The package never adds global scopes — that is the tenant package's job.

```php
// Host app with Spatie Multitenancy — the app scopes, not this package
$team->billing()->subscribe('pro');
// internally: Subscription::create(['billable_type' => Team::class, 'billable_id' => $team->id, ...])
```

---

## PHP 8.4 / Laravel 12 usage

- `readonly` classes for value objects (`PendingTransaction`, `FeatureCheck`)
- Constructor property promotion throughout
- Named arguments at all public API boundaries
- First-class callable syntax where appropriate
- Enums for status fields (`SubscriptionStatus`, `InvoiceStatus`, `TransactionStatus`)
- `#[Attribute]` hooks reserved for future compile-time validation
