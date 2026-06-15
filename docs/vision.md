# Vision

## What is laracaise/billing?

`laracaise/billing` is a Laravel package that gives any Eloquent model the ability to subscribe to plans, track feature usage, receive invoices, and pay through pluggable payment drivers — without locking the application into a specific tenant architecture, UI framework, or payment provider.

---

## Problem statement

Laravel's existing billing ecosystem is split:

| Tool | Gap |
|---|---|
| Laravel Cashier (Stripe) | Stripe-only; one billable model assumed; no usage limits |
| Laravel Cashier (Paddle) | Paddle-only; no metered usage |
| Spark | Opinionated SaaS scaffold, not a reusable library |
| Manual implementations | Repeated boilerplate per project |

None of these work out-of-the-box with Paystack, which is the dominant payment gateway in South Africa and much of sub-Saharan Africa. None offer plan-level feature gating as a first-class concept.

---

## Goals

1. **Any billable model** — `User`, `Team`, `Organisation`, `Tenant` — all supported via a trait. No assumption about which model pays.
2. **Plan-based subscriptions** with named features and limits (seats, API calls, storage, etc.).
3. **Usage tracking** — increment, decrement, and gate access against plan limits in real time.
4. **Manual billing** — create and issue invoices without a payment gateway (bank transfer, EFT, purchase orders).
5. **Paystack** as the first payment driver with a clean contract so other gateways can be added.
6. **Multi-tenant agnostic** — the package does not know or care about tenancy. The consuming application decides which model is billable and in what tenant context.
7. **Filament-optional** — full headless functionality; a separate `laracaise/billing-filament` panel is planned but not bundled.
8. **Testable by default** — every driver can be faked; events allow assertions without hitting real gateways.

---

## Non-goals

- This package will **not** manage user authentication or registration.
- This package will **not** implement multi-tenancy itself.
- This package will **not** provide a pre-built UI (Blade views, Livewire components, or Filament resources in this package).
- This package will **not** generate PDF invoices or render printable invoice documents — that responsibility belongs to the host application or a dedicated rendering package.
- This package will **not** support Stripe, Paddle, or other gateways in v1 (contracts exist so the community can add them).
- This package will **not** handle tax calculations beyond storing a tax rate on the plan/invoice.

---

## Target users

- South African and African SaaS developers building subscription products on Laravel.
- Developers who need Paystack billing without writing custom gateway integration per project.
- Teams running multi-tenant applications (Spatie Multitenancy, Tenancy for Laravel, custom) who need billing that sits outside the tenant layer.

---

## Design principles

- **Explicit over magic** — callers opt into billing via a trait; nothing is auto-wired.
- **Driver pattern** — gateways are interchangeable without changing calling code.
- **Immutable financial records** — invoices and transactions are never deleted; only statuses change.
- **Event-driven** — every significant lifecycle moment fires a Laravel event so the host application can react without modifying package internals.
- **Zero opinion on tenancy** — polymorphic morph columns handle any Eloquent model as the owner. Each ownership table uses a semantically scoped morph name (`subscriptionable_*`, `invoiceable_*`, `billable_*`) so relationships are unambiguous.
