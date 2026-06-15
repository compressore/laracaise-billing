# Payment Drivers

## Driver contract

Every driver must implement `Laracaise\Billing\Contracts\PaymentDriverInterface`:

```php
namespace Laracaise\Billing\Contracts;

interface PaymentDriverInterface
{
    /**
     * Directly charge a stored payment method for a pending payment record.
     */
    public function charge(Payment $payment, array $options = []): Payment;

    /**
     * Begin a hosted/redirect checkout flow. Returns a PendingTransaction
     * with a checkoutUrl or access_code the frontend can consume.
     */
    public function initializeTransaction(Payment $payment, array $options = []): PendingTransaction;

    /**
     * Verify a completed transaction by the provider's reference string.
     * Used in webhook handlers and return-URL handlers.
     * Must return the existing Payment record if already verified (idempotent).
     */
    public function verifyTransaction(string $reference): Payment;

    /**
     * Issue a full or partial refund. Amount in smallest currency unit.
     * Null amount = full refund.
     */
    public function refund(Payment $payment, ?int $amountInCents = null): Payment;

    /**
     * Create or retrieve a provider-side customer record for a billable model.
     * Returns the provider's customer identifier.
     */
    public function createCustomer(Billable $billable, array $data = []): string;

    /**
     * A unique machine name for this driver, stored on Payment records.
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
        'secret_key'     => env('PAYSTACK_SECRET_KEY'),
        'public_key'     => env('PAYSTACK_PUBLIC_KEY'),
        'base_url'       => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        'channels'       => ['card', 'bank', 'ussd', 'bank_transfer'],
        'currency'       => env('BILLING_CURRENCY', 'ZAR'),
    ],
],
```

**Flow — hosted checkout:**

```
App → initializeTransaction(payment)
    → POST /transaction/initialize  (Paystack)
    ← { authorization_url, access_code, reference }
App → redirect user to $pending->checkoutUrl          (authorization_url)
    or open Paystack inline popup with $pending->meta['access_code']
User completes payment on Paystack
Paystack → webhook → POST /billing/webhook/paystack  (App)
App → verifyTransaction(reference)
    → GET /transaction/verify/:reference  (Paystack)
    ← transaction object
Package → marks payment succeeded, fires PaymentSucceeded + SubscriptionRenewed
```

**Flow — charge authorization (recurring):**

```
App → charge(payment, ['authorization_code' => 'AUTH_xxx'])
    → POST /transaction/charge_authorization  (Paystack)
    ← transaction object
Package → records Payment with status succeeded
```

**Webhook endpoint:**

The package registers `POST /billing/webhook/paystack` automatically when the service provider boots (if routing is enabled in config). The route is excluded from CSRF. The handler processes events in this exact sequence:

1. **Verify signature** — check `X-Paystack-Signature` HMAC. Return `401` and log `warning` if invalid. Do not process the payload.
2. **Verify transaction** — call `GET /transaction/verify/:reference` to confirm the event state directly with Paystack, regardless of what the webhook payload claims.
3. **Check idempotency** — attempt to insert into `billing_webhook_events` with `(provider='paystack', provider_event_id=data.id)`. If a unique constraint violation occurs, the event was already processed — return `200` immediately.
4. **Process in a DB transaction** — all state changes (subscription status, payment record, etc.) happen inside the same database transaction as the `billing_webhook_events` row insert.
5. **Commit and fire events** — after the DB transaction commits, dispatch domain events (`PaymentSucceeded`, `SubscriptionRenewed`, etc.).

Supported webhook events: `charge.success`, `charge.failed`, `refund.processed`, `subscription.create`, `subscription.disable`.

---

### `manual`

Production driver for out-of-band payment collection (EFT, bank transfer, purchase orders, cash). Makes no HTTP calls. On `charge()`, it creates a `Payment` with status `pending` and returns immediately. An operator later marks the payment paid (via admin UI or `billing:mark-paid {reference}` command), which fires `PaymentSucceeded`.

```php
'driver' => 'manual',
```

No configuration keys required. Suitable as the default driver for businesses that invoice and collect offline.

---

### `null`

**Test-only** no-op driver. All methods discard input and return in-memory successful responses. Never configure this in a production environment — it silently swallows all payment calls. Use `manual` for real out-of-band billing and `Billing::fake()` for test isolation.

```php
'driver' => 'null',
```

No configuration keys required.

---

## `PendingTransaction` fields

`initializeTransaction` returns a `PendingTransaction` value object. The contract is driver-agnostic:

| Field | Type | Content |
|---|---|---|
| `$reference` | `string` | Unique transaction reference |
| `$checkoutUrl` | `string` | Redirect URL for hosted checkout |
| `$meta` | `array` | Driver-specific fields — not part of the stable contract |
| `$raw` | `array` | Full raw provider response, stored for audit |

Paystack-specific values available in `$meta`:

```php
$pending->meta['access_code']  // Paystack inline popup access code
$pending->meta['channel']      // e.g. 'card', 'bank'
```

Any future driver (Stripe, Yoco, Flutterwave) adds its own keys to `$meta` without breaking the contract. Callers that need a driver-specific key should document the dependency.

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

Your driver class must implement `PaymentDriverInterface`. The `name()` method must return `'flutterwave'`. This name is stored on `billing_payments.provider` and `billing_webhook_events.provider`, so it must be stable across deployments.

---

## Driver resolution

1. `config('laracaise-billing.driver')` is read on every request.
2. `BillingManager::driver(?string $name)` resolves via the registered driver map.
3. Drivers are constructed lazily (only when first used).
4. Per-subscription provider override: stored on `subscriptions.provider`; used when charging renewal payments so the original provider is always used even if the default changes.

---

## Faking drivers in tests

```php
// In your test or TestCase::setUp()
Billing::fake();

// Assert specific interactions
Billing::assertCharged(Payment $payment);
Billing::assertTransactionVerified(string $reference);
Billing::assertRefunded(Payment $payment);
Billing::assertNoCharges();
```

`Billing::fake()` replaces all driver instances with `FakeDriver`, which records calls in memory and never contacts an external API.

---

## Security: Webhook verification and idempotency

### Signature verification

Every inbound webhook **must** be verified before processing. The `PaystackDriver` verifies the `X-Paystack-Signature` header using `hash_hmac('sha512', $rawBody, $secretKey)`. Requests that fail verification return HTTP `401` and are logged at `warning` level. Never process webhook payloads before verification.

### Idempotency

Payment gateways retry webhook delivery on network failures, which means the same event may arrive more than once. The package guards against this with the `billing_webhook_events` table:

- Each event is identified by `(provider, provider_event_id)` — a unique constraint on this pair prevents double-processing at the database level.
- If the insert fails due to a unique violation, the handler returns `200` without re-processing. This is correct — the gateway considers `200` a successful acknowledgement.
- All downstream state changes happen inside the same transaction as the `billing_webhook_events` insert, so partial processing is not possible.

Additionally, `billing_payments` carries a unique index on `(provider, provider_reference)`. A second `verifyTransaction` call for the same reference returns the existing `Payment` record rather than creating a duplicate.

---

## Currency handling

All amounts passed to driver methods are already in the smallest currency unit (cents, kobo). Drivers must not divide or multiply by 100 internally — the `Payment` model holds the correct integer value. Paystack's API expects amounts in kobo (for NGN) or cents (for ZAR, USD), so no conversion is needed for those currencies.
