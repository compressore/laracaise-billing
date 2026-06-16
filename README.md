# Laracaise Billing

[![Latest Version on Packagist](https://img.shields.io/packagist/v/laracaise/billing.svg?style=flat-square)](https://packagist.org/packages/laracaise/billing)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/laracaise/billing/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/laracaise/billing/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/laracaise/billing.svg?style=flat-square)](https://packagist.org/packages/laracaise/billing)
[![License](https://img.shields.io/packagist/l/laracaise/billing.svg?style=flat-square)](LICENSE)

A flexible billing package for Laravel applications.

> **Work in progress.** API is not stable. Do not use in production yet.

---

## Requirements

- PHP ^8.4
- Laravel ^12.0

## Installation

```bash
composer require laracaise/billing
php artisan billing:install   # publishes config + migrations
php artisan migrate
```

`billing:install` is a single shortcut for the two `vendor:publish` commands. If you need to re-publish after an upgrade, pass `--force`.

---

## Quick start

After installation, the minimum to get billing working:

**1. Add the `Billable` trait to your model**

```php
// app/Models/User.php  (or Team, Organisation, etc.)
use Laracaise\Billing\Concerns\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

**2. Define at least one plan** in `config/laracaise-billing.php`, then sync it:

```php
'plans' => [
    'pro' => [
        'name'     => 'Pro',
        'amount'   => 125_00,       // in cents (R125.00)
        'currency' => 'ZAR',
        'interval' => 'monthly',
        'features' => [
            'reports'   => ['value' => true,  'resettable' => false], // flag: on/off
            'api_calls' => ['value' => 1000,  'resettable' => true],  // numeric cap
            'storage'   => ['value' => null,  'resettable' => false], // null = unlimited
        ],
    ],
],
```

```bash
php artisan billing:sync
```

**3. Subscribe a user**

```php
$plan = \Laracaise\Billing\Models\Plan::where('slug', 'pro')->firstOrFail();
$user->billing()->subscribe($plan);
```

**4. Gate a route**

```php
Route::middleware(['auth', 'billing.active'])->group(function () {
    Route::get('/app', DashboardController::class);
});
```

For a full walkthrough — Paystack checkout, usage tracking, multi-tenant setup — see [`docs/integration-guides.md`](docs/integration-guides.md).

---

## Configuration

After publishing, the config file lives at `config/laracaise-billing.php`.

```php
return [
    'driver'   => env('LARACAISE_BILLING_DRIVER', 'manual'),
    'currency' => env('BILLING_CURRENCY', 'ZAR'),

    'usage_tracking' => [
        'locking' => env('BILLING_USAGE_LOCKING', 'atomic'), // atomic | pessimistic | none
    ],

    'drivers'  => [
        'manual' => [],

        'paystack' => [
            'public_key'     => env('PAYSTACK_PUBLIC_KEY'),
            'secret_key'     => env('PAYSTACK_SECRET_KEY'),
            'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
            'base_url'       => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        ],
    ],
];
```

For local Paystack development, use Paystack test credentials only:

```dotenv
LARACAISE_BILLING_DRIVER=paystack
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_WEBHOOK_SECRET=xxxxx
```

## Usage

See the `docs/` directory for full documentation:

| Document | Contents |
|---|---|
| [`docs/integration-guides.md`](docs/integration-guides.md) | Installation, standard app setup, multi-tenant setup, Filament admin guide |
| [`docs/public-api.md`](docs/public-api.md) | Full `BillingContext` API reference |
| [`docs/architecture.md`](docs/architecture.md) | Layer overview, service descriptions, middleware, events |
| [`docs/payment-drivers.md`](docs/payment-drivers.md) | Paystack, Manual, Null drivers; custom driver registration |
| [`docs/database-schema.md`](docs/database-schema.md) | All table schemas and relationships |
| [`docs/testing-strategy.md`](docs/testing-strategy.md) | Test layout, factories, driver faking, webhook testing |

### Route middleware

The package registers three route middleware aliases:

```php
Route::middleware('billing.active')->group(function () {
    Route::get('/app', DashboardController::class);
});

Route::get('/reports', ReportsController::class)
    ->middleware('billing.feature:reports');

Route::get('/teams/{team}/settings', TeamSettingsController::class)
    ->middleware([
        'billing.not_suspended:default,team',
        'billing.feature:team_settings,default,team',
    ]);
```

Without a route parameter, middleware checks the authenticated user. When guarding a route-bound billable model, pass the subscription name and route parameter name after the alias.

### Artisan commands

```bash
php artisan billing:install                                   # publish config + migrations
php artisan billing:sync                                      # seed plans from config
php artisan billing:reset-usage {subscription_id}             # reset usage counters
php artisan billing:expire-subscriptions                      # expire ended subscriptions
php artisan billing:process-renewals                          # advance billing periods
```

`billing:sync` reads plan definitions from `config/laracaise-billing.php` (see Quick start above for the format). The command is idempotent — safe to re-run in CI or deployment pipelines.

Schedule recurring maintenance commands in your application:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:process-renewals')->hourly();
Schedule::command('billing:expire-subscriptions')->daily();
```

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## Code of Conduct

See [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md).

## Security

If you discover a security vulnerability, please email c.sangwa@yahoo.fr instead of opening an issue.

## License

MIT. See [LICENSE](LICENSE).
