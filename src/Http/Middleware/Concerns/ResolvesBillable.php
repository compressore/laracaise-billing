<?php

declare(strict_types=1);

namespace Laracaise\Billing\Http\Middleware\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait ResolvesBillable
{
    private function resolveBillable(Request $request, ?string $routeParameter = null): ?Model
    {
        $billable = $routeParameter !== null && $routeParameter !== ''
            ? $request->route($routeParameter)
            : $request->user();

        if ($billable instanceof Model && method_exists($billable, 'billing')) {
            return $billable;
        }

        return null;
    }
}
