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

];
