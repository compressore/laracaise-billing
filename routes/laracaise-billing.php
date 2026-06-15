<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laracaise\Billing\Http\Controllers\PaystackWebhookController;

Route::post('/billing/webhook/paystack', PaystackWebhookController::class)
    ->name('laracaise-billing.webhook.paystack');
