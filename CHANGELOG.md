# Changelog

All notable changes to `laracaise/billing` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.7.0] — 2026-06-15

### Added
- Route middleware `billing.active`, `billing.feature`, and `billing.not_suspended` with configurable route-parameter targeting
- `MiddlewareAliasRegistrar` — centralises alias map, registers on both Router and HttpKernel
- Grace-period coverage: `billing.active` returns 200 during grace period (Cancelled + future `current_period_end`) and 402 once it has lapsed
- `afterResolving(HttpKernelContract::class)` hook in the service provider so aliases survive Testbench's kernel reset in test environments
- Pint job added to the GitHub Actions CI matrix (runs `vendor/bin/pint --test`)

### Changed
- `LaracaiseBillingServiceProvider` delegates middleware registration to `MiddlewareAliasRegistrar`
- `vendor/bin/phpstan analyse` now reports 0 errors at level 9

### Documentation
- `docs/architecture.md` — added Route middleware section: alias/class/status table, 402 vs 403 rationale, grace-period semantics, Testbench bootstrap ordering explanation
- `docs/integration-guides.md` — comprehensive guide covering standard app, multi-tenant SaaS, Filament admin panel, and why Filament is optional
- README — simplified installation to `billing:install`, added Quick Start section with inline feature-type comments

## [0.6.0] — 2026-06-01

### Added
- Artisan commands: `billing:install`, `billing:sync`, `billing:reset-usage`, `billing:expire-subscriptions`, `billing:process-renewals`
- `billing:sync` reads plan and feature definitions from `config/laracaise-billing.plans` and upserts them idempotently
- Events: `SubscriptionActivated`, `SubscriptionCancelled`, `SubscriptionExpired`, `SubscriptionGracePeriodStarted`, `SubscriptionResumed`, `SubscriptionSwapped`, `UsageConsumed`, `UsageReset`, `PaymentCreated`, `PaymentSucceeded`, `PaymentFailed`, `PaymentRefunded`
- `BillingManager` facade entry points: `Billing::plan()`, `Billing::fake()`, `Billing::driver()`
- Routes stub registered by the service provider

## [0.5.0] — 2026-05-25

### Added
- `PaystackDriver` — `initializeTransaction`, `verifyTransaction`, `createCustomer`, `charge`, `refund`
- Paystack webhook controller with HMAC-SHA512 signature verification
- Idempotent webhook handler: deduplicates on `(provider, provider_event_id)`; processes inside a DB transaction
- Webhook event handlers: `charge.success`, `charge.failed`, `refund.processed`
- `billing_webhook_events` migration with unique index on `(provider, provider_event_id)`
- `PaymentDriverInterface` contract
- `ManualDriver` — pending-payment creation and operator mark-paid flow
- `NullDriver` — no-op driver for testing and local development
- `FakeDriver` + `Billing::fake()` for test-suite isolation

## [0.4.0] — 2026-05-18

### Added
- `BillingContext` — per-entity billing entry point returned by `$model->billing()`
- `SubscriptionService` — `subscribe`, `swap`, `cancel`, `resume`, `expire`; enforces single active subscription per name
- `UsageService` — `consume`, `used`, `remaining`, `canConsume` with configurable locking (`atomic`, `pessimistic`, `none`)
- `FeatureService` — `hasFeature`, `canUse`, `featureValue`
- `DuplicateSubscriptionException`, `UsageExceededException`
- `Billing` facade alias

## [0.3.0] — 2026-05-10

### Added
- `Plan` model with `features()` relation and factory
- `PlanFeature` model with factory
- `Subscription` model — status methods (`isActive`, `onGracePeriod`, `onTrial`, `isCancelled`), scopes (`active`, `cancelled`, `onGracePeriod`), and factory
- `SubscriptionOverride` model + factory
- `UsageRecord` model (append-only, no `updated_at`) + factory
- `Payment` model + factory
- `Billable` trait — `subscriptions()`, `payments()`, `billing()` relations and entry point
- Enums: `BillingInterval`, `SubscriptionStatus`, `PaymentStatus`, `PaymentType`

## [0.2.0] — 2026-05-03

### Added
- Migrations: `billing_plans`, `billing_plan_features`, `billing_subscriptions`, `billing_usage_records`, `billing_payments`
- Database schema documentation in `docs/database-schema.md`

## [0.1.0] — 2026-04-26

### Added
- Package skeleton: `LaracaiseBillingServiceProvider`, config stub, PSR-4 autoloading
- Pest v3 test bootstrap with Orchestra Testbench v10 and in-memory SQLite
- GitHub Actions CI: Pest + PHPStan jobs
- Laravel Pint (Laravel preset, strict types, alphabetical imports)
- Larastan level 9 static analysis
- `docs/vision.md`, `docs/architecture.md`, `docs/public-api.md`, `docs/payment-drivers.md`, `docs/testing-strategy.md`, `docs/roadmap.md`
- MIT LICENSE, README, CONTRIBUTING, CODE_OF_CONDUCT

[Unreleased]: https://github.com/laracaise/billing/compare/v0.7.0...HEAD
[0.7.0]: https://github.com/laracaise/billing/compare/v0.6.0...v0.7.0
[0.6.0]: https://github.com/laracaise/billing/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/laracaise/billing/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/laracaise/billing/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/laracaise/billing/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/laracaise/billing/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/laracaise/billing/releases/tag/v0.1.0
