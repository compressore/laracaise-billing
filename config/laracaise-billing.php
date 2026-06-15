<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Billing Driver
    |--------------------------------------------------------------------------
    |
    | The default payment gateway driver. Supported drivers will be listed
    | here as they are implemented.
    |
    */

    'driver' => env('LARACAISE_BILLING_DRIVER', 'manual'),

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | The default currency for all billing operations, expressed as an ISO
    | 4217 currency code (e.g. ZAR, USD, NGN).
    |
    */

    'currency' => env('BILLING_CURRENCY', 'ZAR'),

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Driver-specific configuration. Secret values must come from the host
    | application's environment and must never be hardcoded in the package.
    |
    | For local/development Paystack testing, use Paystack test keys only:
    | pk_test_* for the public key and sk_test_* for the secret key.
    |
    */

    'drivers' => [
        'manual' => [],

        'paystack' => [
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
            'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage Tracking
    |--------------------------------------------------------------------------
    |
    | Controls concurrency protection when recording feature usage.
    |
    |   atomic      — re-checks the aggregate inside a DB transaction (default)
    |   pessimistic — also acquires SELECT FOR UPDATE on the subscription row
    |   none        — no transaction; suitable only for low-traffic scenarios
    |
    */

    'usage_tracking' => [
        'locking' => env('BILLING_USAGE_LOCKING', 'atomic'),
    ],

];
