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

## Phase 2 — Database layer 🔲

- [ ] Migration: `billing_plans`
- [ ] Migration: `billing_plan_features`
- [ ] Migration: `billing_subscriptions`
- [ ] Migration: `billing_usage_records`
- [ ] Migration: `billing_invoices`
- [ ] Migration: `billing_invoice_items`
- [ ] Migration: `billing_transactions`
- [ ] Migration: `billing_payment_methods`
- [ ] Enums: `SubscriptionStatus`, `InvoiceStatus`, `TransactionStatus`

---

## Phase 3 — Eloquent models 🔲

- [ ] `Plan` model + `PlanFeature` model with relationship
- [ ] `Subscription` model with status machine methods (no service yet)
- [ ] `UsageRecord` model (append-only)
- [ ] `Invoice` + `InvoiceItem` models
- [ ] `Transaction` model (immutable after creation)
- [ ] `PaymentMethod` model
- [ ] `Billable` trait (relations only, no logic)
- [ ] Model factories for all models

---

## Phase 4 — Plan management 🔲

- [ ] `PlanRepository` — CRUD for plans and features
- [ ] `Artisan billing:plans` — list plans
- [ ] `Artisan billing:sync-plans` — seed from config
- [ ] Tests: plan creation, feature attachment, slug uniqueness

---

## Phase 5 — Subscriptions 🔲

- [ ] `SubscriptionService` — subscribe, swap, cancel, resume
- [ ] `BillingContext` — first methods: `subscribe()`, `cancel()`, `onPlan()`, `subscribed()`
- [ ] Subscription state machine + status transitions
- [ ] Events: `SubscriptionCreated`, `SubscriptionCancelled`, `SubscriptionSwapped`, `SubscriptionResumed`
- [ ] Tests: full lifecycle for each transition

---

## Phase 6 — Usage limits 🔲

- [ ] `UsageService` — `record()`, `used()`, `remaining()`, `canUse()`
- [ ] `BillingContext` — usage methods
- [ ] `UsageExceededException`
- [ ] Period reset logic tied to `current_period_end`
- [ ] Tests: limit enforcement, unlimited features, flag features, period reset

---

## Phase 7 — Manual billing & invoicing 🔲

- [ ] `InvoiceService` — build, issue, void
- [ ] `InvoiceBuilder` — fluent builder returned by `->invoice()`
- [ ] Invoice number auto-generation (`INV-YYYY-NNNN`)
- [ ] Events: `InvoiceCreated`, `InvoiceIssued`, `InvoicePaid`, `InvoiceVoided`
- [ ] Tests: line items, tax calculation, number sequence

---

## Phase 8 — Paystack driver 🔲

- [ ] `PaymentDriverInterface` contract
- [ ] `NullDriver` (test-only no-op)
- [ ] `ManualDriver` (production out-of-band: EFT, bank transfer)
- [ ] `PaystackDriver` — `initializeTransaction`, `verifyTransaction`, `charge`, `refund`, `createCustomer`
- [ ] Paystack webhook controller + route registration
- [ ] HMAC signature verification middleware
- [ ] Webhook event handlers: `charge.success`, `charge.failed`, `refund.processed`
- [ ] `BillingManager::extend()` for custom drivers
- [ ] `Billing::fake()` + `FakeDriver` with assertions
- [ ] Tests: all driver methods (mocked HTTP), webhook verification, signature rejection

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
- [ ] `Artisan billing:mark-paid {reference}` — operator marks a ManualDriver transaction paid
- [ ] `Billing::fake()` documentation and examples
- [ ] Published config documentation comments
- [ ] Example `AppServiceProvider` snippets
- [ ] PHPDoc on all public classes

---

## Future / community 💡

- `laracaise/billing-filament` — Filament v3 panel with plan manager, subscription table, invoice viewer
- Additional drivers: Stripe, Flutterwave, Ozow, SnapScan
- Laravel Nova resource pack
- Webhooks for outbound events (host app subscribes to billing lifecycle via webhooks to its own endpoints)
- Proration support for mid-cycle plan swaps
- Coupon / discount code support
- Multi-currency subscription support

---

## Versioning policy

- `v0.x` — unstable; API may change between minor versions
- `v1.0` — stable public API (Phases 2–9 complete, all tests green, Larastan level 9)
- `v1.x` — backwards-compatible additions only
- `v2.0` — breaking changes, new major with migration guide
