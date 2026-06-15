# Database Schema

All tables are prefixed with `billing_`. Column names follow snake_case. No foreign-key constraints cross package boundaries (morph columns are intentionally unconstrained so any model can be the owner).

Each ownership table uses a scoped morph name that reflects the relationship from the child's perspective:

| Table | Morph columns | Why |
|---|---|---|
| `billing_subscriptions` | `subscriptionable_type / _id` | The subscription belongs to a *subscriptionable* |
| `billing_payments` | `subscriptionable_type / _id` | The payment belongs to a *subscriptionable* |

**Morph ID column type**: `subscriptionable_id` (and equivalent `_id` columns) is stored as `varchar`. This means it accepts the string representation of any primary key — integer IDs, UUIDs, and ULIDs — without schema changes. Never assume integer-only IDs in queries.

Monetary amounts are stored as **integers in the smallest currency unit** (cents for ZAR/USD, kobo for NGN). This eliminates floating-point rounding errors.

---

## `billing_plans`

Defines the available subscription tiers.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `name` | `string` | Display name, e.g. "Professional" |
| `slug` | `string` unique | Machine key, e.g. `pro` |
| `description` | `text` nullable | |
| `amount` | `unsignedBigInteger` | In cents |
| `currency` | `char(3)` | ISO 4217, e.g. `ZAR` |
| `interval` | `string` | `monthly`, `yearly`, `weekly`, `once` |
| `interval_count` | `unsignedTinyInteger` default 1 | e.g. 3 = every 3 months |
| `trial_days` | `unsignedSmallInteger` default 0 | |
| `is_active` | `boolean` default true | Soft-disable without deleting |
| `sort_order` | `unsignedSmallInteger` default 0 | For display ordering |
| `metadata` | `json` nullable | Gateway-specific IDs, custom fields |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

Index: `(is_active, sort_order)`.

---

## `billing_plan_features`

Features (limits or flags) attached to a plan.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `plan_id` | `ulid` FK → `billing_plans` | |
| `feature` | `string` | Machine key, e.g. `api_calls`, `seats`, `storage_gb` |
| `value` | `string` nullable | `null` = unlimited; numeric string = hard limit; `true`/`false` = flag |
| `resettable` | `boolean` default true | Whether usage resets each billing cycle |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

Index: `(plan_id, feature)` unique.

---

## `billing_subscriptions`

One row per subscription per billable entity.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `subscriptionable_type` | `string` | Morph class name (output of `getMorphClass()`) |
| `subscriptionable_id` | `string` | Morph ID — varchar, supports integers, UUIDs, ULIDs |
| `plan_id` | `ulid` FK → `billing_plans` | |
| `name` | `string` default `default` | Allows multiple concurrent subscriptions, e.g. `default`, `addon` |
| `status` | `string` | `pending`, `trialing`, `active`, `past_due`, `cancelled` |
| `quantity` | `unsignedSmallInteger` default 1 | For seat-based plans |
| `trial_ends_at` | `timestamp` nullable | |
| `current_period_start` | `timestamp` nullable | |
| `current_period_end` | `timestamp` nullable | |
| `cancels_at` | `timestamp` nullable | Scheduled cancellation |
| `cancelled_at` | `timestamp` nullable | Actual cancellation |
| `provider` | `string` nullable | Driver name that owns this subscription, e.g. `paystack` |
| `provider_id` | `string` nullable | Remote subscription ID from the provider |
| `metadata` | `json` nullable | |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

Indexes:
- `(subscriptionable_type, subscriptionable_id)` — owner lookup
- `(subscriptionable_type, subscriptionable_id, name, status)` — active-subscription check
- `status`
- `current_period_end`
- `cancels_at`
- `trial_ends_at`
- `provider`, `provider_id`

**Multi-subscription constraint**: at most one `active` or `trialing` subscription per `(subscriptionable_type, subscriptionable_id, name)` is enforced by `SubscriptionService` before insert. On MySQL and PostgreSQL, a partial unique index on this triple filtered to `status IN ('active', 'trialing')` provides an additional database-level guard.

---

## `billing_usage_records`

Append-only log of usage increments for metered features.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `subscription_id` | `ulid` FK → `billing_subscriptions` | |
| `feature` | `string` | Must match a `billing_plan_features.feature` key |
| `quantity` | `integer` | Positive = increment, negative = decrement/correction |
| `recorded_at` | `timestamp` | Defaults to now; allows backfilling |
| `created_at` | `timestamp` | |

No `updated_at` — records are immutable. Corrections are made by inserting a negative quantity row.

Indexes: `(subscription_id, feature)`, `(subscription_id, feature, recorded_at)`.

**Concurrency**: inserts are wrapped in a DB transaction that re-checks the period aggregate before committing. See `usage_tracking.locking` in the architecture doc.

---

## `billing_payments`

Immutable record of every payment attempt or refund.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `subscriptionable_type` | `string` | Morph class name of the direct owner |
| `subscriptionable_id` | `string` | Morph ID — varchar, supports integers, UUIDs, ULIDs |
| `subscription_id` | `ulid` FK → `billing_subscriptions` nullable | Null for standalone payments not tied to a subscription |
| `amount` | `unsignedBigInteger` | In cents |
| `currency` | `char(3)` | ISO 4217 |
| `status` | `string` | `pending`, `succeeded`, `failed`, `refunded` |
| `type` | `string` | `charge`, `refund` |
| `provider` | `string` nullable | Driver name, e.g. `paystack`, `manual` |
| `provider_reference` | `string` nullable | Gateway's transaction reference |
| `provider_response` | `json` nullable | Raw gateway payload stored for audit |
| `metadata` | `json` nullable | Application-level metadata |
| `paid_at` | `timestamp` nullable | |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

Indexes:
- `(subscriptionable_type, subscriptionable_id)` — owner lookup
- `status`
- `paid_at`
- `(provider, provider_reference)` — provider lookup

**Unique constraint**: `(provider, provider_reference)` where both columns are not null. This is the database-level guard against payment reference duplication. The `PaystackDriver` also checks for an existing payment by reference before inserting.

No soft deletes — payment records are never deleted.

---

## `billing_webhook_events`

Idempotency log for inbound provider webhook events. Prevents duplicate processing when a gateway delivers the same event more than once.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `provider` | `string` | Driver name, e.g. `paystack` |
| `provider_event_id` | `string` | Provider's unique identifier for this event (e.g. Paystack's `data.id`) |
| `event_type` | `string` | Provider event name, e.g. `charge.success` |
| `payload` | `json` nullable | Full raw webhook payload for audit |
| `processed_at` | `timestamp` | When the package successfully processed this event |
| `created_at` | `timestamp` | |

**Unique index**: `(provider, provider_event_id)`. On receipt, the handler attempts to insert into this table. A unique-constraint violation means the event was already processed — return `200` immediately without re-processing.

No `updated_at` — rows are written once and never modified.

---

## `billing_payment_methods` _(planned)_

Stored/tokenised payment methods for a billable entity.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `billable_type` | `string` | Morph class name |
| `billable_id` | `string` | Morph ID — varchar, supports integers, UUIDs, ULIDs |
| `provider` | `string` | Driver name |
| `provider_token` | `string` | Reusable token from the provider |
| `type` | `string` | `card`, `bank_account`, etc. |
| `last_four` | `char(4)` nullable | |
| `brand` | `string` nullable | e.g. `visa`, `mastercard` |
| `expiry_month` | `unsignedTinyInteger` nullable | |
| `expiry_year` | `unsignedSmallInteger` nullable | |
| `is_default` | `boolean` default false | |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

Index: `(billable_type, billable_id, is_default)`.

---

## `billing_invoices` _(planned)_

One invoice per billing event (renewal, manual, one-off). The `number` field is a free-format string — sequential numbering, tax compliance requirements, and gap prevention are the responsibility of the host application.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `invoiceable_type` | `string` | Morph class name |
| `invoiceable_id` | `string` | Morph ID — varchar, supports integers, UUIDs, ULIDs |
| `subscription_id` | `ulid` FK nullable | Null for manual invoices |
| `number` | `string` unique | Host-application-assigned reference; format is not constrained |
| `status` | `string` | `draft`, `open`, `paid`, `void`, `uncollectible` |
| `subtotal` | `unsignedBigInteger` | In cents |
| `tax_rate` | `decimal(5,2)` default 0.00 | e.g. 15.00 for 15% VAT |
| `tax` | `unsignedBigInteger` | Calculated: `subtotal * tax_rate / 100` |
| `total` | `unsignedBigInteger` | `subtotal + tax` |
| `currency` | `char(3)` | |
| `due_at` | `timestamp` nullable | |
| `paid_at` | `timestamp` nullable | |
| `voided_at` | `timestamp` nullable | |
| `notes` | `text` nullable | Shown on the invoice |
| `provider` | `string` nullable | Driver used to collect payment |
| `provider_invoice_id` | `string` nullable | Remote ID from provider |
| `metadata` | `json` nullable | |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

---

## `billing_invoice_items` _(planned)_

Line items on an invoice.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `invoice_id` | `ulid` FK → `billing_invoices` | |
| `description` | `string` | |
| `quantity` | `unsignedSmallInteger` default 1 | |
| `unit_amount` | `unsignedBigInteger` | In cents |
| `amount` | `unsignedBigInteger` | `quantity * unit_amount` |
| `metadata` | `json` nullable | |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

---

## Entity relationships

```
Plan ──< PlanFeature
Plan ──< Subscription >── Subscriptionable (morph: subscriptionable_type/id)
Subscription ──< SubscriptionOverride
Subscription ──< UsageRecord
Subscription ──< Payment
Payment >── Subscriptionable (morph: subscriptionable_type/id)
WebhookEvent (keyed by provider + provider_event_id; no FK to Payment)
[Future] Subscription ──< Invoice >── Invoiceable (morph: invoiceable_type/id)
[Future] Invoice ──< InvoiceItem
[Future] Billable ──< PaymentMethod (morph: billable_type/id)
```

---

## Migration strategy

- All migrations published via `--tag="laracaise-billing-migrations"`.
- Migrations run in numbered order: `0001_create_billing_plans_table`, etc.
- No migration modifies tables owned by the host application.
- ULIDs are used for all PKs to avoid integer enumeration and to support distributed ID generation without a central sequence.
- The `billing_webhook_events` table is created in the same migration batch as the Paystack driver (Phase 8). It is required for idempotent webhook processing.
