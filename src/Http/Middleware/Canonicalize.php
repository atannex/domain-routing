<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Middleware;

use Atannex\DomainRouting\Edge\CanonicalTarget;
use Atannex\DomainRouting\Events\CanonicalRedirected;
use Atannex\DomainRouting\Resolution\SiteContext;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Global: one redirect to the canonical scheme, host and path. Never chains hops. */
final class Canonicalize
{
    public function __construct(private readonly SiteContext $context, private readonly CanonicalTarget $target) {}

    public function handle(Request $request, Closure $next): Response
    {
        $match = $this->context->current();

        if ($match === null || $match->source === SiteMatch::DEFAULT) {
            return $next($request);
        }

        $target = $this->target->for($request, $match);

        if ($target === null) {
            return $next($request);
        }

        [$url, $status] = $target;

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            if ((int) config('domain-routing.canonical.non_get', 421) !== 308) {
                return new Response('Misdirected Request', 421);
            }

            $status = 308;
        }

        event(new CanonicalRedirected($request->fullUrl(), $url, $status));

        return new RedirectResponse($url, $status);
    }
}
