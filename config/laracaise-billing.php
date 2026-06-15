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

    'driver' => env('BILLING_DRIVER', 'paystack'),

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
    | Driver-specific configuration. Keys are added here as drivers are
    | implemented.
    |
    */

    'drivers' => [
        // 'paystack' => [...]
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
