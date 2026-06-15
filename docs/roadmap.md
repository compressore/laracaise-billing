# Roadmap

Status key: ✅ Done · 🔄 In progress · 🔲 Planned · 💡 Idea

---

## Phase 0 — Package foundation ✅

- [x] `composer.json` with PSR-4, PHP 8.4, Laravel 12
- [x] `LaracaiseBillingServiceProvider`
- [x] Config stub
- [x] Pest test bootstrap
- [x] Laravel Pint + Larastan (level 9)
- [x] GitHub Actions CI
- [x] MIT LICENSE, README, CHANGELOG, CONTRIBUTING, CODE_OF_CONDUCT

---

## Phase 1 — Documentation ✅

- [x] `docs/vision.md`
- [x] `docs/architecture.md`
- [x] `docs/database-schema.md`
- [x] `docs/public-api.md`
- [x] `docs/payment-drivers.md`
- [x] `docs/roadmap.md`
- [x] `docs/testing-strategy.md`

---

## Phase 2 — Database layer ✅

- [x] Migration: `billing_plans`
- [x] Migration: `billing_plan_features`
- [x] Migration: `billing_subscriptions` (columns: `provider`, `provider_id`)
- [x] Migration: `billing_usage_records`
- [x] Migration: `billing_payments` (columns: `provider`, `provider_reference`, `provider_response`)
- [x] Enums: `BillingInterval`, `SubscriptionStatus`, `PaymentStatus`, `PaymentType`

Migrations for `billing_webhook_events`, `billing_payment_methods`, and `billing_invoices` are planned in later phases.

---

## Phase 3 — Eloquent models ✅

- [x] `Plan` model + factory
- [x] `PlanFeature` model + factory
- [x] `Subscription` model with status methods and scopes + factory
- [x] `SubscriptionOverride` model + factory
- [x] `UsageRecord` model (append-only, no `updated_at`) + factory
- [x] `Payment` model + factory
- [x] `Billable` trait (relations only — `subscriptions()`, `payments()`)

---

## Phase 4 — Plan management 🔲

- [ ] `PlanRepository` — CRUD for plans and features
- [ ] `Artisan billing:plans` — list plans
- [ ] `Artisan billing:sync-plans` — seed from config
- [ ] Tests: plan creation, feature attachment, slug uniqueness

---

## Phase 5 — Subscriptions 🔲

- [ ] `SubscriptionService` — subscribe, swap, cancel, resume
- [ ] `DuplicateSubscriptionException` — raised when a second active/trialing subscription with the same name is attempted
- [ ] `BillingContext` — first methods: `subscribe()`, `cancel()`, `onPlan()`, `subscribed()`
- [ ] Subscription state machine + status transitions
- [ ] Multi-subscription collision enforcement: service layer check + partial unique index migration (MySQL/PG)
- [ ] Events: `SubscriptionCreated`, `SubscriptionCancelled`, `SubscriptionSwapped`, `SubscriptionResumed`
- [ ] Tests: full lifecycle for each transition, `DuplicateSubscriptionException` on collision

---

## Phase 6 — Usage limits 🔲

- [ ] `UsageService` — `consume()`, `used()`, `remaining()`, `canConsume()`
- [ ] `BillingContext` — usage methods
- [ ] `UsageExceededException`
- [ ] `usage_tracking.locking` config key: `atomic` (default), `pessimistic`, `none`
- [ ] Concurrency-safe consume: re-check aggregate inside DB transaction before inserting `UsageRecord`
- [ ] Period reset logic tied to `current_period_end`
- [ ] Tests: limit enforcement, unlimited features, flag features, period reset, limit re-check at transaction boundary

---

## Phase 7 — Integration guides and optional UI strategy ✅

- [x] `docs/integration-guides.md`
  - [x] Installation guide (composer, publish, migrate, env, scheduler)
  - [x] Standard single-tenant app guide (User as billable)
  - [x] Multi-tenant SaaS guide (Team/Organisation as billable, tenancy-agnostic)
  - [x] Filament admin panel guide (Plans, Subscriptions, Payments, Usage, Overrides)
  - [x] Why Filament is optional and the `laracaise/billing-filament` separation rationale

---

## Phase 8 — Paystack driver 🔲

- [ ] `PaymentDriverInterface` contract
- [ ] `PaystackDriver` — `initializeTransaction`, `verifyTransaction`, `charge`, `refund`, `createCustomer`
- [ ] Migration: `billing_webhook_events` with unique index on `(provider, provider_event_id)`
- [ ] Unique index migration: `billing_payments(provider, provider_reference)`
- [ ] Paystack webhook controller + route registration
- [ ] HMAC signature verification middleware
- [ ] Idempotent webhook handler: verify signature → verify transaction → check `billing_webhook_events` → process in DB transaction → commit and fire events
- [ ] Webhook event handlers: `charge.success`, `charge.failed`, `refund.processed`
- [ ] Tests: all driver methods (mocked HTTP), signature rejection, duplicate webhook delivery (idempotency), payment reference uniqueness

---

## Phase 9 — Renewal & scheduling 🔲

- [ ] `RenewalService` — queries subscriptions due for renewal
- [ ] `ProcessSubscriptionRenewals` artisan command (designed to be scheduled)
- [ ] Retry logic for `past_due` subscriptions (configurable attempts + delay)
- [ ] Events: `SubscriptionRenewed`, `SubscriptionPastDue`
- [ ] Tests: renewal timing, retry exhaustion, past_due → cancelled transition

---

## Phase 10 — Developer experience 🔲

- [ ] `Artisan billing:verify {reference}` — manual transaction check
- [ ] Published config documentation comments
- [ ] Example `AppServiceProvider` snippets
- [ ] PHPDoc on all public classes
- [ ] Pre-release audit: remove `minimum-stability: dev`, add version tags, clean up `composer.json` constraints

---

## Future / community 💡

- `laracaise/billing-filament` — Filament v3 panel with plan manager, subscription table, payment viewer
- Additional drivers: Stripe, Flutterwave, Ozow, SnapScan, Yoco
- Laravel Nova resource pack
- Webhooks for outbound events (host app subscribes to billing lifecycle via webhooks to its own endpoints)
- Proration support for mid-cycle plan swaps
- Coupon / discount code support
- Multi-currency subscription support
- Laravel 13 CI matrix entry (added when Laravel 13 releases and all tests confirm passing)

---

## Versioning policy

- `v0.x` — unstable; API may change between minor versions
- `v1.0` — stable public API (Phases 4–10 complete, all tests green, Larastan level 9 clean)
- `v1.x` — backwards-compatible additions only
- `v2.0` — breaking changes, new major with migration guide

**Pre-release stability**: `minimum-stability: dev` is permitted during active development (`v0.x`). Before tagging `v1.0`:
- Remove or raise `minimum-stability` to `stable`
- Ensure all dependencies have stable releases
- Review and clean `composer.json` version constraints
- Tag a release candidate and run the full CI matrix
