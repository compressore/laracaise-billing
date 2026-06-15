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
│  Subscription   │          │   Usage / Manual Billing     │
│  Service        │          │   Services                   │
└──────┬──────────┘          └──────────┬──────────────────┘
       │                                │
┌──────▼────────────────────────────────▼──────────────────┐
│                  Domain Models (Eloquent)                 │
│  Plan · PlanFeature · Subscription · SubscriptionOverride│
│  UsageRecord · Payment · WebhookEvent                    │
│  [Future] Invoice · InvoiceItem · PaymentMethod          │
└────────────────────────┬─────────────────────────────────┘
                         │
┌────────────────────────▼─────────────────────────────────┐
│                  Driver Layer                            │
│  PaymentDriverInterface → PaystackDriver                 │
│                           ManualDriver | NullDriver      │
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
- `driver(string $name): PaymentDriverInterface`

### `Billable` trait

Mixed into any Eloquent model that needs billing. Provides:

- `billing(): BillingContext` — returns a fluent context object scoped to this model
- `subscriptions(): MorphMany` — via `subscriptionable` morph on `billing_subscriptions`
- `payments(): MorphMany` — via `subscriptionable` morph on `billing_payments`

The trait imposes no assumption about which Eloquent model uses it. `User`, `Team`, `Organisation`, `Tenant`, and any custom model are all valid.

### `BillingContext`

A short-lived value object that wraps a billable model and delegates to `BillingManager`. Allows the ergonomic fluent API without polluting the model itself:

```php
$team->billing()->onPlan('pro')
$team->billing()->subscribe('pro')
$team->billing()->usage('api_calls')->increment(10)
```

### `SubscriptionService`

Handles the state machine for subscriptions:

```
pending → active → past_due → cancelled
                ↘ trialing ↗
```

Transitions fire events. No transition bypasses the service (models do not mutate their own status).

Before creating a new `active` or `trialing` subscription, the service checks for an existing one with the same `(subscriptionable_type, subscriptionable_id, name)` and raises `DuplicateSubscriptionException` if found. See [Multi-subscription collision](#multi-subscription-collision-medium-severity).

### `UsageService`

Reads the billable's active subscription, looks up the feature limit on the plan, and compares against the current period's `UsageRecord` sum. Returns `bool`, `int` (remaining), or throws `UsageExceededException`.

**Concurrency control** is configured via `config('laracaise-billing.usage_tracking.locking')`:

| Value | Behaviour |
|---|---|
| `atomic` | Wraps the read-check-increment in a single DB transaction. Re-checks the aggregate `SUM(quantity)` inside the transaction before inserting the new `UsageRecord`, so a concurrent request that passes the initial check cannot cause an overage. **Default.** |
| `pessimistic` | Acquires a `SELECT FOR UPDATE` lock on the subscription row before reading usage, then inserts within the same transaction. Stronger guarantee; higher lock contention. |
| `none` | No locking. Acceptable only in low-concurrency environments where minor over-counting is tolerable. |

The limit is always re-verified inside the DB transaction before the `UsageRecord` insert, regardless of the locking mode.

### `PaymentDriverInterface`

```php
interface PaymentDriverInterface
{
    public function charge(Payment $payment, array $options = []): Payment;
    public function initializeTransaction(Payment $payment, array $options = []): PendingTransaction;
    public function verifyTransaction(string $reference): Payment;
    public function refund(Payment $payment, ?int $amountInCents = null): Payment;
    public function createCustomer(Billable $billable, array $data = []): string;
    public function name(): string;
}
```

### `ManualDriver`

A production driver for out-of-band billing (EFT, bank transfer, purchase orders). Makes no HTTP calls. Creates a `Payment` in `pending` status and waits for an operator to mark it paid via the admin interface or `billing:mark-paid` command. Fires `PaymentSucceeded` once confirmed.

### `NullDriver`

A **test-only** no-op driver. All methods discard their input and return successful-looking responses in memory. Never configure this in a production environment — use `ManualDriver` for real out-of-band billing.

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
| `PaymentSucceeded` | Gateway or operator confirms payment |
| `PaymentFailed` | Gateway rejects payment |
| `PaymentRefunded` | Refund completed |
| `UsageExceeded` | A usage check finds the limit has been hit |

---

## Configuration resolution order

1. `config/laracaise-billing.php` in host app (published)
2. Package default config (merged via `mergeConfigFrom`)
3. Per-subscription overrides (stored on `subscriptions.metadata` JSON column)

---

## Multi-tenancy stance

The package uses polymorphic morph columns on every ownership table. Each table uses a semantically scoped morph name to keep relationships unambiguous:

| Table | Morph columns | Relationship name |
|---|---|---|
| `billing_subscriptions` | `subscriptionable_type`, `subscriptionable_id` | `subscriptionable` |
| `billing_payments` | `subscriptionable_type`, `subscriptionable_id` | `subscriptionable` |

`subscriptionable_id` is stored as `varchar`. This means the column holds the string representation of the owner's primary key and supports integer IDs (stored as `"1"`), UUIDs, and ULIDs without schema changes. The morph class name (e.g. the output of `Model::getMorphClass()`) is stored in `subscriptionable_type`.

The host application is responsible for scoping queries to the correct tenant. The package never adds global scopes — that is the tenant package's job.

```php
// Host app with Spatie Multitenancy — the app scopes, not this package
$entity->billing()->subscribe('pro');
// internally: Subscription::create([
//     'subscriptionable_type' => $entity->getMorphClass(),
//     'subscriptionable_id'   => (string) $entity->getKey(),
//     ...
// ])
```

---

## Idempotency and concurrency risks

### Usage tracking race condition (High severity)

Without locking, two concurrent requests can both read the current usage sum, both find it below the limit, and both insert a `UsageRecord` — exceeding the feature limit. Mitigation: the `usage_tracking.locking` config (see `UsageService` above). The default `atomic` mode re-checks the aggregate sum inside a DB transaction immediately before the insert, so a concurrent insert that completes between the initial check and the write is detected and rejected.

### Webhook replay / duplicate processing (High severity)

Payment gateways may deliver the same webhook event more than once (retries after timeout, duplicate deliveries). Without idempotency, a second `charge.success` event for the same payment could double-activate or double-renew a subscription.

Mitigation — the webhook handler must follow this sequence:

1. **Verify signature** — reject with `401` if HMAC does not match.
2. **Verify transaction** — call `verifyTransaction(reference)` against the gateway to confirm the event state.
3. **Check idempotency** — attempt `INSERT INTO billing_webhook_events (provider, provider_event_id, ...)`. If a unique constraint violation occurs, the event was already processed; return `200` immediately without re-processing.
4. **Process inside a DB transaction** — subscription state changes and `Payment` record mutations happen inside the same DB transaction as the `billing_webhook_events` row.
5. **Commit** — fire domain events (`PaymentSucceeded`, `SubscriptionRenewed`, etc.) after the transaction commits.

### Payment reference duplication (Medium severity)

A provider reference value must be unique per provider. The `billing_payments` table carries a unique index on `(provider, provider_reference)`. If a second `verifyTransaction` call arrives with the same reference, the driver must detect the existing `Payment` record and return it rather than creating a duplicate charge.

### Multi-subscription collision (Medium severity)

An entity must not hold more than one `active` or `trialing` subscription per name. Enforcement happens at two layers:

- **Service layer**: `SubscriptionService::subscribe()` queries for an existing active or trialing subscription with the same `(subscriptionable_type, subscriptionable_id, name)` before creating a new one, and raises `DuplicateSubscriptionException` if found.
- **Database layer**: On MySQL and PostgreSQL, a partial unique index on `(subscriptionable_type, subscriptionable_id, name)` filtered to `status IN ('active', 'trialing')` provides an additional guard. SQLite (used in tests) relies on the service layer check.

---

## PHP 8.4 / Laravel 12 usage

- `readonly` classes for value objects (`PendingTransaction`, `FeatureCheck`)
- Constructor property promotion throughout
- Named arguments at all public API boundaries
- First-class callable syntax where appropriate
- Enums for status fields (`SubscriptionStatus`, `PaymentStatus`)
- `#[Attribute]` hooks reserved for future compile-time validation

**Laravel 13**: support will be added to the CI matrix once Laravel 13 is released and the full test suite passes against it. The `composer.json` constraint will be widened at that point only. Do not claim Laravel 13 support until CI confirms it.
