<?php

declare(strict_types=1);

namespace Laracaise\Billing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laracaise\Billing\Http\Middleware\Concerns\ResolvesBillable;
use Symfony\Component\HttpFoundation\Response;

final class EnsureNotSuspended
{
    use ResolvesBillable;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $name = 'default', ?string $routeParameter = null): Response
    {
        $billable = $this->resolveBillable($request, $routeParameter);

        if ($billable !== null && $billable->billing()->isSuspended($name)) {
            abort(Response::HTTP_FORBIDDEN, 'The subscription is suspended.');
        }

        return $next($request);
    }
}
