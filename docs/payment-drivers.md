# Payment Drivers

## Driver contract

Every driver must implement `Laracaise\Billing\Contracts\PaymentDriverInterface`:

```php
namespace Laracaise\Billing\Contracts;

interface PaymentDriverInterface
{
    /**
     * Directly charge a stored payment method for an invoice.
     */
    public function charge(Invoice $invoice, array $options = []): Transaction;

    /**
     * Begin a hosted/redirect checkout flow. Returns a PendingTransaction
     * with a checkoutUrl or accessCode the frontend can consume.
     */
    public function initializeTransaction(Invoice $invoice, array $options = []): PendingTransaction;

    /**
     * Verify a completed transaction by the gateway's reference string.
     * Used in webhooks and return-URL handlers.
     */
    public function verifyTransaction(string $reference): Transaction;

    /**
     * Issue a full or partial refund. Amount in smallest currency unit.
     * Null amount = full refund.
     */
    public function refund(Transaction $transaction, ?int $amountInCents = null): Transaction;

    /**
     * Create or retrieve a gateway-side customer record for a billable model.
     * Returns the gateway's customer identifier.
     */
    public function createCustomer(Billable $billable, array $data = []): string;

    /**
     * A unique machine name for this driver, used in config and stored on records.
     */
    public function name(): string;
}
```

---

## Built-in drivers

### `paystack`

South Africa / Nigeria / Ghana / Kenya — primary driver for v1.

**Configuration (`config/laracaise-billing.php`):**

```php
'drivers' => [
    'paystack' => [
        'secret_key'   => env('PAYSTACK_SECRET_KEY'),
        'public_key'   => env('PAYSTACK_PUBLIC_KEY'),
        'base_url'     => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'), // for HMAC verification
        'channels'     => ['card', 'bank', 'ussd', 'bank_transfer'], // allowed channels
        'currency'     => env('BILLING_CURRENCY', 'ZAR'),
    ],
],
```

**Flow — hosted checkout:**

```
App → initializeTransaction(invoice)
    → POST /transaction/initialize  (Paystack)
    ← { authorization_url, access_code, reference }
App → redirect user to authorization_url
    or open Paystack inline popup with access_code
User completes payment on Paystack
Paystack → webhook → POST /billing/webhook/paystack  (App)
App → verifyTransaction(reference)
    → GET /transaction/verify/:reference  (Paystack)
    ← transaction object
Package → marks invoice paid, fires InvoicePaid + TransactionSucceeded
```

**Flow — charge authorization (recurring):**

```
App → charge(invoice, ['authorization_code' => 'AUTH_xxx'])
    → POST /transaction/charge_authorization  (Paystack)
    ← transaction object
Package → records Transaction, updates invoice
```

**Webhook endpoint:**

The package registers `POST /billing/webhook/paystack` automatically when the service provider boots (if routing is enabled in config). The route is excluded from CSRF. The handler:

1. Verifies `X-Paystack-Signature` HMAC.
2. Dispatches to the appropriate internal event handler based on `event` field.
3. Supported events: `charge.success`, `charge.failed`, `refund.processed`, `subscription.create`, `subscription.disable`.

---

### `null`

No-op driver for testing and manual-only setups. Always returns a successful `Transaction`.

```php
'driver' => 'null',
```

No configuration keys required.

---

## Registering a custom driver

In your application's `AppServiceProvider`:

```php
use Laracaise\Billing\Facades\Billing;

Billing::extend('flutterwave', function ($app) {
    return new FlutterwaveDriver(
        config('billing.drivers.flutterwave')
    );
});
```

Your driver class must implement `PaymentDriverInterface`. The `name()` method must return `'flutterwave'`.

---

## Driver resolution

1. `config('laracaise-billing.driver')` is read on every request.
2. `BillingManager::driver(?string $name)` resolves via the registered driver map.
3. Drivers are constructed lazily (only when first used).
4. Per-subscription driver override: stored on `subscriptions.gateway`; used when charging renewal invoices so the original driver is always used even if the default changes.

---

## Faking drivers in tests

```php
// In your test or TestCase::setUp()
Billing::fake();

// Assert specific interactions
Billing::assertCharged(Invoice $invoice);
Billing::assertTransactionVerified(string $reference);
Billing::assertRefunded(Transaction $transaction);
Billing::assertNoCharges();
```

`Billing::fake()` replaces all driver instances with `FakeDriver`, which records calls in memory and never contacts an external API.

---

## Security: Webhook verification

Every inbound webhook **must** be verified before processing. The `PaystackDriver` verifies the `X-Paystack-Signature` header using `hash_hmac('sha512', $rawBody, $secretKey)`. Requests that fail verification return HTTP `401` and are logged at `warning` level.

Never process webhook payloads before verification.

---

## Currency handling

All amounts passed to driver methods are already in the smallest currency unit (cents, kobo). Drivers must not divide or multiply by 100 internally — the `Invoice` model holds the correct integer value. Paystack's API expects amounts in kobo (for NGN) or cents (for ZAR, USD), so no conversion is needed for those currencies.
