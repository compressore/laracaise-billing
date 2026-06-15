# Database Schema

All tables are prefixed with `billing_`. Column names follow snake_case. No foreign-key constraints cross package boundaries (the `billable_id` morph is intentionally unconstrained so any model can be billable).

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
| `interval` | `enum` | `monthly`, `yearly`, `once` |
| `interval_count` | `unsignedTinyInteger` default 1 | e.g. 3 = every 3 months |
| `trial_days` | `unsignedSmallInteger` default 0 | |
| `is_active` | `boolean` default true | Soft-disable without deleting |
| `sort_order` | `unsignedSmallInteger` default 0 | For display ordering |
| `metadata` | `json` nullable | Gateway-specific IDs, custom fields |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

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

One row per subscription per billable model.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `billable_type` | `string` | Morph type |
| `billable_id` | `string` | Morph ID (string to support ULIDs/UUIDs) |
| `plan_id` | `ulid` FK → `billing_plans` | Snapshot at subscription time |
| `name` | `string` default `default` | Allows multiple concurrent subscriptions, e.g. `default`, `addon` |
| `status` | `enum` | `pending`, `trialing`, `active`, `past_due`, `cancelled` |
| `quantity` | `unsignedSmallInteger` default 1 | For seat-based plans |
| `trial_ends_at` | `timestamp` nullable | |
| `current_period_start` | `timestamp` nullable | |
| `current_period_end` | `timestamp` nullable | |
| `cancels_at` | `timestamp` nullable | Scheduled cancellation |
| `cancelled_at` | `timestamp` nullable | Actual cancellation |
| `gateway` | `string` nullable | Driver name that owns this subscription |
| `gateway_subscription_id` | `string` nullable | Remote ID from the gateway |
| `metadata` | `json` nullable | |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

Index: `(billable_type, billable_id, name, status)`.

---

## `billing_usage_records`

Append-only log of usage increments for metered features.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `subscription_id` | `ulid` FK → `billing_subscriptions` | |
| `feature` | `string` | Must match a `billing_plan_features.feature` key |
| `quantity` | `integer` | Positive = increment, negative = decrement |
| `recorded_at` | `timestamp` | Defaults to now; allows backfilling |
| `created_at` | `timestamp` | |

No `updated_at` — records are immutable. Corrections are made by inserting a negative quantity row.

Index: `(subscription_id, feature, recorded_at)`.

---

## `billing_invoices`

One invoice per billing event (renewal, manual, one-off).

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `billable_type` | `string` | Morph |
| `billable_id` | `string` | Morph |
| `subscription_id` | `ulid` FK nullable | Null for manual invoices |
| `number` | `string` unique | Human-readable, e.g. `INV-2026-0001` |
| `status` | `enum` | `draft`, `open`, `paid`, `void`, `uncollectible` |
| `subtotal` | `unsignedBigInteger` | In cents |
| `tax_rate` | `decimal(5,2)` default 0.00 | e.g. 15.00 for 15% VAT |
| `tax` | `unsignedBigInteger` | Calculated: `subtotal * tax_rate / 100` |
| `total` | `unsignedBigInteger` | `subtotal + tax` |
| `currency` | `char(3)` | |
| `due_at` | `timestamp` nullable | |
| `paid_at` | `timestamp` nullable | |
| `voided_at` | `timestamp` nullable | |
| `notes` | `text` nullable | Shown on the invoice |
| `gateway` | `string` nullable | Driver used to collect payment |
| `gateway_invoice_id` | `string` nullable | Remote ID from gateway |
| `metadata` | `json` nullable | |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

---

## `billing_invoice_items`

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

## `billing_transactions`

Immutable record of every payment attempt.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `invoice_id` | `ulid` FK → `billing_invoices` | |
| `gateway` | `string` | Driver name |
| `gateway_reference` | `string` | Gateway's transaction reference |
| `type` | `enum` | `charge`, `refund` |
| `status` | `enum` | `pending`, `succeeded`, `failed`, `reversed` |
| `amount` | `unsignedBigInteger` | In cents |
| `currency` | `char(3)` | |
| `gateway_response` | `json` nullable | Raw gateway payload stored for audit |
| `created_at` | `timestamp` | |
| `updated_at` | `timestamp` | |

No soft deletes — records are never deleted.

---

## `billing_payment_methods`

Stored/tokenised payment methods for a billable model.

| Column | Type | Notes |
|---|---|---|
| `id` | `ulid` PK | |
| `billable_type` | `string` | Morph |
| `billable_id` | `string` | Morph |
| `gateway` | `string` | Driver name |
| `gateway_token` | `string` | Reusable token from gateway |
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

## Entity relationships

```
Plan ──< PlanFeature
Plan ──< Subscription >── Billable (morph)
Subscription ──< UsageRecord
Subscription ──< Invoice >── Billable (morph)
Invoice ──< InvoiceItem
Invoice ──< Transaction
Billable ──< PaymentMethod (morph)
```

---

## Migration strategy

- All migrations published via `--tag="laracaise-billing-migrations"`.
- Migrations run in numbered order: `0001_create_billing_plans_table`, etc.
- No migration modifies tables owned by the host application.
- ULIDs are used for all PKs to avoid integer enumeration and to support distributed ID generation without a central sequence.
