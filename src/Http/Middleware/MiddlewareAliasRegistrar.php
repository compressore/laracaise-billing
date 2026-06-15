<?php

declare(strict_types=1);

namespace Laracaise\Billing\Http\Middleware;

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as FoundationKernel;
use Illuminate\Routing\Router;

final class MiddlewareAliasRegistrar
{
    /** @var array<string, class-string> */
    public const array ALIASES = [
        'billing.active' => EnsureSubscriptionActive::class,
        'billing.feature' => EnsureFeatureAvailable::class,
        'billing.not_suspended' => EnsureNotSuspended::class,
    ];

    public static function registerOnRouter(Router $router): void
    {
        foreach (self::ALIASES as $alias => $class) {
            $router->aliasMiddleware($alias, $class);
        }
    }

    /**
     * Merge billing aliases into the kernel's $middlewareAliases property.
     *
     * The service provider already covers the normal request path by registering
     * aliases on the Router. This method covers the kernel's own terminateMiddleware()
     * path and any non-standard bootstrap flows (e.g. Testbench Foundation\Application
     * outside of PHPUnit) where Testbench's resolveApplicationHttpMiddlewares() hook
     * overwrites the kernel's $middlewareAliases after the service provider has booted.
     */
    public static function registerOnKernel(HttpKernelContract $kernel): void
    {
        if (! $kernel instanceof FoundationKernel) {
            return;
        }

        $kernel->setMiddlewareAliases(
            array_merge($kernel->getMiddlewareAliases(), self::ALIASES)
        );
    }
}
