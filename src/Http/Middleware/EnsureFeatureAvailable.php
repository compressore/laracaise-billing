<?php

declare(strict_types=1);

namespace Laracaise\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laracaise\Billing\Http\Middleware\Concerns\ResolvesBillable;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFeatureAvailable
{
    use ResolvesBillable;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(
        Request $request,
        Closure $next,
        string $feature,
        string $name = 'default',
        ?string $routeParameter = null,
    ): Response {
        $billable = $this->resolveBillable($request, $routeParameter);

        if ($billable === null || ! $billable->billing()->hasFeature($feature, $name)) {
            abort(Response::HTTP_PAYMENT_REQUIRED, "The [{$feature}] feature is not available.");
        }

        return $next($request);
    }
}
