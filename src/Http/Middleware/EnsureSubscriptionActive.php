<?php

declare(strict_types=1);

namespace Laracaise\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laracaise\Billing\Http\Middleware\Concerns\ResolvesBillable;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSubscriptionActive
{
    use ResolvesBillable;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $name = 'default', ?string $routeParameter = null): Response
    {
        $billable = $this->resolveBillable($request, $routeParameter);
        $subscription = $billable?->billing()->subscription($name);

        if ($subscription === null || (! $subscription->status->isAccessible() && ! $subscription->onGracePeriod())) {
            abort(Response::HTTP_PAYMENT_REQUIRED, 'An active subscription is required.');
        }

        return $next($request);
    }
}
