<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Middleware;

use Atannex\DomainRouting\Resolution\SiteContext;
use Atannex\DomainRouting\Routing\SiteRoutes;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs the current site's own middleware (e.g. tenant, web) for routes that
 * answer on every site, so the package's endpoints see the same tenant,
 * session and database as the site's pages.
 */
final class ApplySiteMiddleware
{
    public function __construct(private readonly SiteContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->context->current()?->site;
        $middleware = $site === null ? ['web'] : SiteRoutes::middleware($site);

        return (new Pipeline(app()))
            ->send($request)
            ->through(app('router')->resolveMiddleware($middleware))
            ->then($next);
    }
}
