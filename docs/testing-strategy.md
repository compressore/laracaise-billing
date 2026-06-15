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
├── Fixtures/
│   └── BillableModel.php           # in-memory Eloquent model for polymorphic tests
├── Unit/
│   ├── ServiceProviderTest.php     # config registered, provider boots
│   ├── Models/
│   │   ├── PlanTest.php
│   │   ├── PlanFeatureTest.php
│   │   ├── SubscriptionTest.php
│   │   ├── SubscriptionOverrideTest.php
│   │   ├── UsageRecordTest.php
│   │   └── PaymentTest.php
│   ├── Services/
│   │   ├── SubscriptionServiceTest.php
│   │   ├── UsageServiceTest.php          # includes concurrency scenarios
│   │   └── InvoiceServiceTest.php
│   └── Drivers/
│       ├── NullDriverTest.php
│       ├── ManualDriverTest.php          # pending payment, mark-paid flow
│       └── PaystackDriverTest.php        # HTTP-mocked
└── Feature/
    ├── BillableTraitTest.php             # polymorphic relationships
    ├── SubscriptionLifecycleTest.php
    ├── UsageLimitTest.php                # includes concurrent usage checks
    ├── WebhookTest.php                   # signature verification + idempotency
    └── DuplicateSubscriptionTest.php     # multi-subscription collision enforcement
```

---

## Test database

`TestCase` uses Orchestra Testbench, which provisions a temporary SQLite (`:memory:`) database. Package migrations are loaded via `defineDatabaseMigrations()`:

```php
protected function defineDatabaseMigrations(): void
{
    $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    Schema::create('test_billables', function (Blueprint $table) {
        $table->ulid('id')->primary();
        $table->string('name')->default('test');
        $table->timestamps();
    });
}
```

`foreign_key_constraints` is enabled in the test SQLite connection so cascade deletes are enforced. Each test runs inside a database transaction that is rolled back after the test, so the database is clean without truncation overhead.

---

## Model factories

Every package model ships with a factory. Tests use factories, not raw `Model::create()` calls, so fixture data stays DRY and readable:

```php
$plan = Plan::factory()->monthly()->create();
$subscription = Subscription::factory()->active()->forOwner($team)->for($plan)->create();
$payment = Payment::factory()->succeeded()->forOwner($team)->create();
```

Factories use placeholder morph values by default and provide a `forOwner(Model $owner)` state method to bind the correct `subscriptionable_type` and `subscriptionable_id`.

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

it('records a payment when a charge succeeds', function () {
    $payment = Payment::factory()->pending()->create();

    $entity->billing()->charge($payment);

    Billing::assertCharged($payment);
    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
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
            'reference'         => 'PAY-test-001',
            'authorization_url' => 'https://checkout.paystack.com/xxxxx',
            'access_code'       => 'xxxxx',
        ],
    ], 200),
]);

$driver = app(PaystackDriver::class);
$result = $driver->initializeTransaction($payment);

expect($result->reference)->toBe('PAY-test-001');
expect($result->meta['access_code'])->toBe('xxxxx');
Http::assertSent(fn ($r) => str_contains($r->url(), '/transaction/initialize'));
```

---

## Webhook testing

### Happy path

```php
it('marks the payment succeeded on charge.success webhook', function () {
    $payment  = Payment::factory()->pending()->create(['provider_reference' => 'PAY-webhook-001']);
    $payload  = json_encode([
        'event' => 'charge.success',
        'data'  => ['id' => 99991, 'reference' => 'PAY-webhook-001', 'amount' => $payment->amount],
    ]);
    $signature = hash_hmac('sha512', $payload, config('laracaise-billing.drivers.paystack.webhook_secret'));

    $this->postJson('/billing/webhook/paystack', json_decode($payload, true), [
        'X-Paystack-Signature' => $signature,
    ])->assertOk();

    expect($payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
    expect(WebhookEvent::where('provider_event_id', 99991)->exists())->toBeTrue();
});
```

### Signature rejection

```php
it('rejects webhooks with an invalid signature', function () {
    $this->postJson('/billing/webhook/paystack', ['event' => 'charge.success'], [
        'X-Paystack-Signature' => 'invalid',
    ])->assertUnauthorized();
});
```

### Idempotency — duplicate delivery

```php
it('does not double-process a webhook event delivered twice', function () {
    $payment  = Payment::factory()->pending()->create(['provider_reference' => 'PAY-dup-001']);
    $payload  = json_encode([
        'event' => 'charge.success',
        'data'  => ['id' => 99992, 'reference' => 'PAY-dup-001', 'amount' => $payment->amount],
    ]);
    $signature = hash_hmac('sha512', $payload, config('laracaise-billing.drivers.paystack.webhook_secret'));
    $headers   = ['X-Paystack-Signature' => $signature];

    // First delivery — processed normally
    $this->postJson('/billing/webhook/paystack', json_decode($payload, true), $headers)->assertOk();

    // Second delivery — must be acknowledged but not re-processed
    $this->postJson('/billing/webhook/paystack', json_decode($payload, true), $headers)->assertOk();

    // Payment succeeded exactly once
    expect(Payment::where('provider_reference', 'PAY-dup-001')->where('status', PaymentStatus::Succeeded)->count())->toBe(1);
    expect(WebhookEvent::where('provider_event_id', 99992)->count())->toBe(1);
});
```

---

## Usage concurrency testing

SQLite's in-memory DB is single-connection, so true concurrent locking cannot be tested in unit tests. Instead:

- **Unit tests** verify that the service re-checks the aggregate inside the transaction and raises `UsageExceededException` when the aggregate equals the limit.
- **Integration notes** document that the `atomic` and `pessimistic` locking modes must be verified against a real MySQL or PostgreSQL instance in the host app's integration suite.

```php
it('raises UsageExceededException when the limit is reached mid-transaction', function () {
    $plan         = Plan::factory()->create();
    $feature      = PlanFeature::factory()->for($plan)->create(['feature' => 'api_calls', 'value' => '10']);
    $subscription = Subscription::factory()->active()->for($plan)->create();

    // Seed usage up to the limit
    UsageRecord::factory()->count(10)->for($subscription)->create(['feature' => 'api_calls', 'quantity' => 1]);

    expect(fn () => app(UsageService::class)->consume($subscription, 'api_calls', 1))
        ->toThrow(UsageExceededException::class);
});
```

---

## Duplicate subscription testing

```php
it('raises DuplicateSubscriptionException when subscribing to an already active plan name', function () {
    $plan   = Plan::factory()->active()->create();
    $entity = BillableModel::factory()->create();

    Subscription::factory()->active()->forOwner($entity)->create(['name' => 'default']);

    expect(fn () => $entity->billing()->subscribe($plan, name: 'default'))
        ->toThrow(DuplicateSubscriptionException::class);
});
```

---

## Event assertions

All events are testable via Laravel's `Event::fake()`:

```php
Event::fake([SubscriptionCreated::class, PaymentSucceeded::class]);

$entity->billing()->subscribe('pro');

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

Laravel 13 will be added to the matrix once it is released and all tests pass against it. Do not add the `^13.0` constraint to `composer.json` before CI confirms it.

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
