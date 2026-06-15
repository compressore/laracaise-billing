# Integration Guides

Concrete instructions for wiring `laracaise/billing` into real applications. Each guide is independent — read only the one that matches your setup.

---

## 1. Installation

### Requirements

- PHP ^8.4
- Laravel ^12.0
- A supported database: MySQL 8+, PostgreSQL 13+, SQLite 3.35+ (development only)

### Composer

```bash
composer require laracaise/billing
```

The service provider registers itself automatically via Laravel's package discovery.

### Publish and migrate

```bash
# Publish the config file
php artisan vendor:publish --tag="laracaise-billing-config"

# Publish migrations into your app's database/migrations/
php artisan vendor:publish --tag="laracaise-billing-migrations"
php artisan migrate
```

Published migrations follow the pattern `0001_create_billing_plans_table.php`. Do not rename them — the numbered prefix controls run order.

### Environment

```dotenv
# Required
LARACAISE_BILLING_DRIVER=manual        # or: paystack
BILLING_CURRENCY=ZAR                   # ISO 4217 — default currency for all amounts

# Paystack (only when LARACAISE_BILLING_DRIVER=paystack)
PAYSTACK_PUBLIC_KEY=pk_test_xxxxx
PAYSTACK_SECRET_KEY=sk_test_xxxxx
PAYSTACK_WEBHOOK_SECRET=xxxxx
```

Use Paystack **test** credentials locally and in CI. Never commit live keys to source control.

### Scheduler

Register two commands in your `routes/console.php` (or equivalent):

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:process-renewals')->hourly();
Schedule::command('billing:expire-subscriptions')->daily();
```

`billing:process-renewals` advances past-due periods and fires `SubscriptionRenewed`. `billing:expire-subscriptions` transitions cancelled subscriptions with an expired `current_period_end` to `expired` status, removing them from active queries.

---

## 2. Standard app (single-tenant, user-based billing)

The most common setup: `User` is the billable model; each user owns their own subscription.

### 2.1 Add the Billable trait

```php
// app/Models/User.php
use Laracaise\Billing\Concerns\Billable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Billable;
}
```

The trait adds a `billing()` method returning a `BillingContext`, and two Eloquent relationships: `subscriptions()` and `payments()`.

### 2.2 Define plans

Plans can be seeded two ways — via config or directly in your database seeder.

**Via config + artisan sync (recommended for simple setups):**

```php
// config/laracaise-billing.php
'plans' => [
    'starter' => [
        'name'     => 'Starter',
        'amount'   => 49_00,          // in cents
        'currency' => 'ZAR',
        'interval' => 'monthly',
        'features' => [
            'api_calls'  => ['value' => 500,   'resettable' => true],
            'team_members' => ['value' => 3,   'resettable' => false],
            'reports'    => ['value' => false,  'resettable' => false],
        ],
    ],
    'pro' => [
        'name'     => 'Pro',
        'amount'   => 125_00,
        'currency' => 'ZAR',
        'interval' => 'monthly',
        'features' => [
            'api_calls'    => ['value' => 5000,  'resettable' => true],
            'team_members' => ['value' => 20,    'resettable' => false],
            'reports'      => ['value' => true,  'resettable' => false],
            'storage_gb'   => ['value' => null,  'resettable' => false], // null = unlimited
        ],
    ],
],
```

```bash
php artisan billing:sync
```

Run `billing:sync` in your deployment pipeline after config changes. The command is idempotent — it updates existing plans rather than creating duplicates.

**Via database seeder (better for complex setups with programmatic control):**

```php
use Laracaise\Billing\Models\Plan;
use Laracaise\Billing\Models\PlanFeature;

$pro = Plan::create([
    'name'     => 'Pro',
    'slug'     => 'pro',
    'amount'   => 125_00,
    'currency' => 'ZAR',
    'interval' => 'monthly',
]);

PlanFeature::create(['plan_id' => $pro->id, 'feature' => 'api_calls', 'value' => '5000', 'resettable' => true]);
PlanFeature::create(['plan_id' => $pro->id, 'feature' => 'reports',   'value' => 'true', 'resettable' => false]);
PlanFeature::create(['plan_id' => $pro->id, 'feature' => 'storage_gb', 'value' => null,  'resettable' => false]);
```

### 2.3 Subscribe a user

```php
use Laracaise\Billing\Models\Plan;

$plan = Plan::where('slug', 'pro')->firstOrFail();

// Subscribe immediately with manual billing (EFT, invoice later)
$subscription = $user->billing()->subscribe($plan);

// Subscribe via Paystack — returns a PendingTransaction with a checkout URL
$pending = $user->billing()->initializePayment(
    $user->billing()->subscribe($plan),
);
return redirect($pending->checkoutUrl);
```

On Paystack, the subscription starts in `pending` status. When Paystack confirms payment via webhook, the package transitions it to `active` and fires `PaymentSucceeded` + `SubscriptionActivated`.

### 2.4 Gate routes with middleware

```php
// routes/web.php
Route::middleware(['auth', 'billing.active'])->group(function () {
    Route::get('/dashboard', DashboardController::class);
    Route::get('/settings',  SettingsController::class);
});

Route::middleware(['auth', 'billing.active', 'billing.feature:reports'])->group(function () {
    Route::get('/reports', ReportsController::class);
});
```

When the authenticated user has no active subscription, `billing.active` responds with `402 Payment Required`. When the plan does not include the `reports` feature, `billing.feature:reports` responds with `402`.

### 2.5 Check entitlements in code

```php
// Controller or policy
if (! $user->billing()->hasFeature('reports')) {
    abort(403, 'Your plan does not include reports.');
}

// Check usage before an expensive operation
if (! $user->billing()->canUse('api_calls')) {
    throw new ApiLimitExceededException();
}

// Record usage (throws UsageLimitExceededException if limit is breached)
$user->billing()->consume('api_calls');
```

### 2.6 Show subscription state in views

```php
// In a Blade component or view composer
$subscription = $user->billing()->subscription();
$plan         = $user->billing()->plan();
$remaining    = $user->billing()->remaining('api_calls');
```

### 2.7 Listen to events

Register listeners in `AppServiceProvider` or an event service provider. Every lifecycle transition fires an event.

```php
use Laracaise\Billing\Events\SubscriptionActivated;
use Laracaise\Billing\Events\SubscriptionCancelled;
use Laracaise\Billing\Events\PaymentSucceeded;
use Laracaise\Billing\Events\UsageLimitReached;

// Send a welcome email when a trial converts to active
Event::listen(SubscriptionActivated::class, function ($event) {
    Mail::to($event->subscription->subscriptionable)->send(
        new WelcomeToProMail($event->subscription)
    );
});

// Notify when usage is approaching the limit
Event::listen(UsageLimitReached::class, function ($event) {
    Notification::send(
        $event->subscription->subscriptionable,
        new UsageLimitWarningNotification($event->feature, $event->used, $event->limit)
    );
});

// Remove Paystack-specific data when a user cancels
Event::listen(SubscriptionCancelled::class, function ($event) {
    // e.g. cancel a recurring charge authorization at Paystack
});
```

---

## 3. Multi-tenant SaaS (tenant-agnostic)

In multi-tenant applications, billing typically belongs to the **tenant** (a `Team`, `Organisation`, or `Workspace` model), not to individual users. The package has no opinion about tenancy — it works with any Eloquent model as the subscription owner.

This guide is written around a `Team` model. Replace it with whatever your tenant model is called.

### 3.1 Make the tenant model billable

```php
// app/Models/Team.php
use Laracaise\Billing\Concerns\Billable;

class Team extends Model
{
    use Billable;
}
```

Users belong to a team, but **only the team subscribes**. Never put `Billable` on `User` in a team-based SaaS — individual users don't own subscriptions.

### 3.2 Resolve the current billable in middleware

The three middleware aliases support a `{routeParameter}` argument to resolve the billable from a route-bound model rather than `Auth::user()`:

```php
// routes/web.php

// Team is resolved from the route segment {team}
Route::prefix('/teams/{team}')
    ->middleware(['auth', 'billing.active:default,team'])
    ->group(function () {
        Route::get('/dashboard', TeamDashboardController::class);

        Route::middleware('billing.feature:reports,default,team')->group(function () {
            Route::get('/reports', TeamReportsController::class);
        });
    });
```

The `billing.active:default,team` syntax passes two arguments to the middleware: subscription name (`default`) and the route parameter name (`team`). The middleware resolves `$request->route('team')` via `SubstituteBindings`, checks that the bound model has the `Billable` trait, and looks up its subscription.

### 3.3 Tenancy packages

The package does not require any tenancy package. Integration is the same regardless of which tenancy solution you use:

#### Spatie Laravel Multitenancy

```php
// The current tenant is always a Team (or your tenant class)
// Spatie's tenant context is handled by its own middleware stack
// billing just reads $request->route('team') — no special integration needed

Route::middleware(['auth', TenantMiddleware::class, 'billing.active:default,team'])
    ->prefix('/teams/{team}')
    ->group(function () {
        // ...
    });
```

No package-level config change is needed. The billing middleware resolves the route-bound team regardless of which database connection is active.

#### Tenancy for Laravel (stancl/tenancy)

If your tenant is the current Eloquent model resolved from the domain or subdomain, pass it explicitly in your own middleware rather than relying on a route parameter:

```php
// app/Http/Middleware/EnsureTeamSubscribed.php
use Laracaise\Billing\Http\Middleware\EnsureSubscriptionActive;

class EnsureTeamSubscribed
{
    public function handle(Request $request, Closure $next): Response
    {
        // Inject the tenant resolved by stancl/tenancy's InitializeTenancyByDomain
        $tenant = tenancy()->tenant;
        $request->setRouteResolver(fn () => tap(
            $request->route(),
            fn ($route) => $route?->setParameter('tenant', $tenant)
        ));

        return app(EnsureSubscriptionActive::class)->handle($request, $next, 'default', 'tenant');
    }
}
```

Alternatively — and more simply — check the subscription directly in a controller base class or middleware without using the billing middleware alias at all:

```php
// In your BaseController or a dedicated SubscriptionGate middleware
if (! tenancy()->tenant?->billing()->subscription()?->status->isAccessible()) {
    abort(402);
}
```

#### Custom tenancy (no package)

If you resolve the current team yourself (from the session, JWT, or subdomain), inject it the same way:

```php
// Your own middleware:
$team = Team::findOrFail(session('current_team_id'));

if (! $team->billing()->hasFeature('reports')) {
    abort(402);
}
```

### 3.4 Subscribing a team

```php
// In your onboarding controller
public function subscribe(Request $request, Team $team): RedirectResponse
{
    $plan = Plan::where('slug', $request->plan)->firstOrFail();

    // Paystack — redirect to hosted checkout
    $subscription = $team->billing()->subscribe($plan);
    $pending      = $team->billing()->initializePayment($subscription);

    return redirect($pending->checkoutUrl);
}
```

Paystack will POST to your webhook when payment completes. The package transitions the subscription to `active`.

### 3.5 Usage tracking per team

```php
// Record usage against the team, not the user
try {
    $team->billing()->consume('api_calls');
} catch (UsageLimitExceededException $e) {
    return response()->json(['error' => 'Monthly API limit reached.'], 429);
}

// Expose remaining usage in the UI
$remaining = $team->billing()->remaining('api_calls');
$used      = $team->billing()->usedQuantity('api_calls');
$limit     = $team->billing()->limit('api_calls');
```

### 3.6 Scoping admin queries to a tenant

The package does not add global scopes. When listing subscriptions or payments in admin views, always scope to the tenant:

```php
// Only this team's subscriptions
$subscriptions = $team->subscriptions()->with('plan')->get();

// Only this team's payments
$payments = $team->payments()->latest('paid_at')->get();
```

### 3.7 Multiple subscription names per team

A single team can hold more than one subscription (e.g. a base plan and add-ons) by using the `name` parameter:

```php
$team->billing()->subscribe($basePlan, name: 'default');
$team->billing()->subscribe($storagePlan, name: 'storage');

// Gate storage features against the add-on subscription
Route::middleware('billing.active:storage,team')->group(function () {
    Route::get('/storage', StorageController::class);
});
```

The package enforces that at most one `active` or `trialing` subscription exists per `(owner, name)` pair. Attempting a second one raises `DuplicateSubscriptionException`.

### 3.8 Per-subscription feature overrides

Subscription overrides let you grant a specific team a higher or lower limit than their plan normally provides — without creating a custom plan:

```php
use Laracaise\Billing\Models\SubscriptionOverride;

// Give this team 20,000 API calls instead of the plan's 5,000
SubscriptionOverride::create([
    'subscription_id' => $subscription->id,
    'feature'         => 'api_calls',
    'value'           => '20000',
    'expires_at'      => now()->addMonths(3),
]);
```

The `FeatureService` always checks for an active override before falling back to the plan's feature value. Expired overrides are ignored.

---

## 4. Filament admin panel

`laracaise/billing` is headless by design. It does not ship Filament resources, pages, or components. This section describes what a Filament-based admin panel for billing **would** look like — either as a bespoke admin you build in your own app, or as a future `laracaise/billing-filament` package.

### 4.1 Plan manager

A Filament Resource for `Laracaise\Billing\Models\Plan` and its `PlanFeature` children.

**PlanResource:**

```php
use Filament\Resources\Resource;
use Laracaise\Billing\Models\Plan;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    // Table columns: name, slug, interval, amount, currency, is_active, sort_order
    // Form fields: all columns above + trial_days + metadata (KeyValueField)

    // RelationManager: PlanFeaturesRelationManager
    //   columns: feature, value, resettable
    //   form:    feature (text), value (text, nullable), resettable (toggle)
}
```

**Actions to add:**
- `billing:sync` trigger — a Filament `Action` that runs `Artisan::call('billing:sync')` and flashes a success notification. Useful when the config plans differ from the database.
- **Deactivate plan** — sets `is_active = false`. Does not affect existing subscriptions. Prevents new subscriptions from selecting this plan.
- **Clone plan** — duplicates a plan and its features under a new slug. Useful for creating variants.

### 4.2 Subscription manager

A Filament Resource for `Laracaise\Billing\Models\Subscription`.

**Table columns:**
- Owner (morph-resolved display name — requires a `getDisplayName()` convention on your models or a presenter)
- Plan name
- Status (badge: `active` → green, `trialing` → blue, `past_due` → orange, `cancelled` → grey, `expired` → red)
- Current period start / end
- Provider + provider ID

**Filters:**
- Status (select)
- Plan (select)
- Expiring within 7 / 30 days (date filter on `current_period_end`)

**Row actions:**
- **Cancel** — calls `$subscription->subscriptionable->billing()->cancel()`. Shows a confirmation modal.
- **Cancel immediately** — calls `cancelNow()`. Use with care.
- **Suspend** — calls the `ManualDriver::suspendSubscription()` — useful for manual dunning.
- **Resume** — transitions `past_due` → `active`.
- **Swap plan** — select a new plan; calls `billing()->swap($newPlan)`.

**RelationManagers to add to the SubscriptionResource:**
- `UsageRecordsRelationManager` — read-only log of usage increments.
- `SubscriptionOverridesRelationManager` — CRUD for feature overrides (see §4.5).
- `PaymentsRelationManager` — read-only payment history for this subscription.

### 4.3 Payment viewer

A Filament Resource for `Laracaise\Billing\Models\Payment` — read-only except for the mark-paid action.

**Table columns:**
- Owner (morph-resolved)
- Amount + currency (formatted)
- Status (badge: succeeded → green, pending → yellow, failed → red, refunded → grey)
- Provider + provider reference
- Paid at

**Row actions:**
- **Mark paid** (`ManualDriver` only) — calls `billing()->verifyTransaction($payment->provider_reference)`. Shows a confirmation modal. Only visible when `$payment->status === PaymentStatus::Pending`.
- **Refund** — calls `billing()->refund($payment)` or a partial amount variant. Requires a text input for partial amount.
- **View raw response** — opens a modal showing `$payment->provider_response` as formatted JSON. Useful for support and audit.

**Global filters:**
- Status
- Provider
- Date range on `paid_at`

### 4.4 Usage dashboard

Usage data is in `billing_usage_records` — an append-only log. A Filament page (not a full resource) is appropriate here.

**Suggested layout:**

```
Owner selector (search)
  ↓
Current subscription summary widget (plan, period, status)
  ↓
Feature usage table:
  | Feature      | Limit   | Used  | Remaining | Resets at           |
  | api_calls    | 5,000   | 1,234 | 3,766     | 2026-07-01 00:00:00 |
  | storage_gb   | ∞       | —     | —         | —                   |
  | reports      | flag    | —     | —         | —                   |
  ↓
Usage record timeline (paginated):
  | Feature   | Quantity | Recorded at          |
  | api_calls | +1       | 2026-06-15 09:12:31  |
  | api_calls | -500     | 2026-06-10 00:00:00  |   ← reset correction
```

To calculate "Used" per feature for the current period:

```php
use Laracaise\Billing\Models\UsageRecord;

$used = UsageRecord::where('subscription_id', $subscription->id)
    ->where('feature', $feature)
    ->whereBetween('recorded_at', [
        $subscription->current_period_start,
        $subscription->current_period_end,
    ])
    ->sum('quantity');
```

**Actions:**
- **Reset usage** — calls `$team->billing()->resetUsage('api_calls')`. Inserts a correction record; does not delete existing rows.
- **Reset all** — calls `$team->billing()->resetUsage()`. Resets all resettable features.

### 4.5 Subscription override manager

A Filament RelationManager attached to `SubscriptionResource` (or a standalone Resource).

**Form fields:**
- Feature (text input — should autocomplete from the subscription's plan features)
- Value (`null` for unlimited, numeric string for a hard limit)
- Expires at (date-time picker, nullable — null means permanent)

**Table columns:**
- Feature
- Value (display `∞` when null)
- Expires at (highlight expired rows in red)

**Row actions:**
- **Edit** — update value or expiry.
- **Delete** — removes the override; plan's default feature value takes effect immediately.

---

## 5. Why Filament is optional

### The package is headless by design

`laracaise/billing` makes no assumption about how you render or manage billing data. Its public surface is a PHP API (`BillingContext`, events, artisan commands). Any UI — Blade views, Livewire components, Filament resources, a JSON API — can sit on top of it without modification.

### Filament is a substantial dependency

Filament v3 pulls in Livewire, Alpine.js, Blade component trees, and its own asset pipeline. Requiring it in the core billing package would:

- Force every app using `laracaise/billing` to install Filament, even those with no admin panel.
- Constrain the Filament version to whatever the billing package pins, creating conflicts for apps that upgrade Filament independently.
- Make the package inappropriate for API-only or non-Filament stacks.

### Separation keeps both packages maintainable independently

When Filament releases a major version, only `laracaise/billing-filament` needs to be updated — not the core package. Teams on the core package are not affected.

When billing logic changes, the Filament package picks up the new PHP API without needing to understand or replicate business rules.

### `laracaise/billing-filament` — planned scope

The planned companion package will:

- Provide all five resources described in §4: Plans, Subscriptions, Payments, Usage, Overrides.
- Register itself as a Filament plugin, discoverable via `$panel->plugins([LaracaiseBillingPlugin::make()])`.
- Support Filament shields / permission integration for controlling which admin roles can see billing data.
- Provide a `BillingWidget` suitable for embedding in a Filament dashboard.

To use the Filament panel, install both packages:

```bash
composer require laracaise/billing laracaise/billing-filament
```

The core package remains a standalone `composer require laracaise/billing` with no UI dependency. Apps that build their own admin interface, use Nova, or expose billing data via an API are not required to use the Filament companion.

---

## 6. Reference: billable model checklist

Use this checklist when adding `Billable` to a new model:

- [ ] Model implements `Billable` trait
- [ ] Primary key type matches the morph ID column type (`varchar` — works with integers, UUIDs, and ULIDs)
- [ ] Plans exist in the database (`billing:sync` or seeder has been run)
- [ ] Default subscription name (`default`) is used unless you need multiple concurrent subscriptions per entity
- [ ] Route middleware applied to all routes that require a paid plan
- [ ] Usage is consumed before — not after — the billable action executes
- [ ] `billing:process-renewals` and `billing:expire-subscriptions` are scheduled
- [ ] A webhook endpoint is configured and reachable by the payment gateway (Paystack or otherwise)
- [ ] `PaymentSucceeded` and `SubscriptionActivated` events are handled if the UI needs to react to async payment confirmation
