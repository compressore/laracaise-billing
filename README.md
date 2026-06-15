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
```

Publish the config file:

```bash
php artisan vendor:publish --tag="laracaise-billing-config"
```

Publish the migrations:

```bash
php artisan vendor:publish --tag="laracaise-billing-migrations"
php artisan migrate
```

## Configuration

After publishing, the config file lives at `config/laracaise-billing.php`.

```php
return [
    'driver'   => env('BILLING_DRIVER', 'paystack'),
    'currency' => env('BILLING_CURRENCY', 'ZAR'),

    'usage_tracking' => [
        'locking' => env('BILLING_USAGE_LOCKING', 'atomic'), // atomic | pessimistic | none
    ],

    'drivers'  => [
        'paystack' => [
            'secret_key'     => env('PAYSTACK_SECRET_KEY'),
            'public_key'     => env('PAYSTACK_PUBLIC_KEY'),
            'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        ],
    ],
];
```

## Usage

Documentation coming soon. See the `docs/` directory for architecture, schema, and API references.

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

If you discover a security vulnerability, please email christian@twomenandatruck.co.za instead of opening an issue.

## License

MIT. See [LICENSE](LICENSE).
