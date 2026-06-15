# Testing Strategy

## Philosophy

- **No test hits a real payment gateway.** Paystack calls are always mocked at the HTTP layer or replaced by `FakeDriver`.
- **No test modifies production data.** Orchestra Testbench spins an in-memory SQLite database per test run.
- **Tests document behaviour.** Pest's descriptive syntax means each `it('...')` block is a specification statement.
- **Larastan level 9 must stay green.** Static analysis is part of CI, not optional.

---

## Test layout

```
tests/
├── Pest.php                        # bootstrap — wires TestCase
├── TestCase.php                    # Orchestra base, loads package provider
├── Unit/
│   ├── ServiceProviderTest.php     # config registered, provider boots
│   ├── Models/
│   │   ├── PlanTest.php
│   │   ├── SubscriptionTest.php
│   │   ├── InvoiceTest.php
│   │   └── UsageRecordTest.php
│   ├── Services/
│   │   ├── SubscriptionServiceTest.php
│   │   ├── UsageServiceTest.php
│   │   └── InvoiceServiceTest.php
│   └── Drivers/
│       ├── NullDriverTest.php
│       ├── ManualDriverTest.php    # pending transaction, mark-paid flow
│       └── PaystackDriverTest.php  # HTTP-mocked
└── Feature/
    ├── BillableTraitTest.php       # end-to-end through BillingContext
    ├── SubscriptionLifecycleTest.php
    ├── UsageLimitTest.php
    ├── ManualInvoicingTest.php
    └── WebhookTest.php             # inbound webhook, HMAC verification
```

---

## Test database

`TestCase` uses Orchestra Testbench, which provisions a temporary SQLite (`:memory:`) database. Package migrations are loaded via `defineDatabaseMigrations()`:

```php
protected function defineDatabaseMigrations(): void
{
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
}
```

Each test runs in a database transaction that is rolled back after the test, so the database is clean for every test without truncation overhead.

---

## Model factories

Every package model ships with a factory registered in the service provider. Tests use factories, not raw `Model::create()` calls, so fixture data stays DRY and readable:

```php
$plan = Plan::factory()->monthly()->withFeature('api_calls', 1000)->create();
$subscription = Subscription::factory()->active()->for($team)->on($plan)->create();
```

---

## Driver isolation in tests

| Driver | Use in tests? | How |
|---|---|---|
| `NullDriver` | Never — it is itself a test primitive | — |
| `ManualDriver` | For testing the operator mark-paid flow | Configure directly; no faking needed |
| `PaystackDriver` | Via `Http::fake()` in unit tests | Mock HTTP responses |
| `FakeDriver` | For feature/integration tests | `Billing::fake()` |

## Faking the driver

`Billing::fake()` replaces all driver instances with `FakeDriver` for the duration of the test:

```php
beforeEach(fn () => Billing::fake());

it('records a transaction when a charge succeeds', function () {
    $invoice = Invoice::factory()->open()->create();

    $team->billing()->charge($invoice);

    Billing::assertCharged($invoice);
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});
```

---

## Mocking HTTP for Paystack

When testing the `PaystackDriver` itself (unit tests), HTTP calls are intercepted with Laravel's `Http::fake()`:

```php
Http::fake([
    'api.paystack.co/transaction/initialize' => Http::response([
        'status' => true,
        'data'   => [
            'reference'        => 'PAY-test-001',
            'authorization_url' => 'https://checkout.paystack.com/xxxxx',
            'access_code'      => 'xxxxx',
        ],
    ], 200),
]);

$driver = app(PaystackDriver::class);
$result = $driver->initializeTransaction($invoice);

expect($result->reference)->toBe('PAY-test-001');
expect($result->meta['access_code'])->toBe('xxxxx');   // Paystack-specific field lives in $meta
Http::assertSent(fn ($r) => str_contains($r->url(), '/transaction/initialize'));
```

---

## Webhook testing

```php
it('marks the invoice paid on charge.success webhook', function () {
    $invoice = Invoice::factory()->open()->create();
    $reference = 'PAY-webhook-001';

    $payload = json_encode([
        'event' => 'charge.success',
        'data'  => ['reference' => $reference, 'amount' => $invoice->total],
    ]);

    $signature = hash_hmac('sha512', $payload, config('laracaise-billing.drivers.paystack.webhook_secret'));

    $this->postJson('/billing/webhook/paystack', json_decode($payload, true), [
        'X-Paystack-Signature' => $signature,
    ])->assertOk();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);
});

it('rejects webhooks with an invalid signature', function () {
    $this->postJson('/billing/webhook/paystack', [], [
        'X-Paystack-Signature' => 'invalid',
    ])->assertUnauthorized();
});
```

---

## Event assertions

All events are testable via Laravel's `Event::fake()`:

```php
Event::fake([SubscriptionCreated::class, InvoiceIssued::class]);

$team->billing()->subscribe('pro');

Event::assertDispatched(SubscriptionCreated::class, fn ($e) => $e->subscription->plan->slug === 'pro');
```

---

## Coverage targets

| Area | Target |
|---|---|
| Models | 100% line coverage |
| Services | 100% line coverage |
| Drivers | 100% line coverage (via HTTP fake) |
| Webhook handler | 100% line coverage |
| Artisan commands | 80% line coverage |
| **Overall** | **≥ 90%** |

Coverage is reported in CI via `composer test:coverage` with `--coverage-clover coverage.xml`. PRs that drop overall coverage below 90% require justification.

---

## Static analysis

Larastan at **level 9** runs on `src/` only (tests are excluded from analysis).

```bash
composer analyse
```

All `@phpstan-ignore` suppressions must include a comment explaining why suppression is necessary.

---

## Continuous integration matrix

| PHP | Laravel | Stability |
|---|---|---|
| 8.4 | 12.* | prefer-stable |
| 8.4 | 13.* | prefer-stable (when released) |

Matrix is defined in `.github/workflows/run-tests.yml`. PHPStan runs as a separate job so test failures and analysis failures are independently visible.

---

## Local development workflow

```bash
# Run all tests
composer test

# Run a specific suite
vendor/bin/pest tests/Unit/

# Run with coverage (requires Xdebug or PCOV)
composer test:coverage

# Static analysis
composer analyse

# Format before committing
composer format
```
