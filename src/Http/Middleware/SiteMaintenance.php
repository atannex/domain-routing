<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Middleware;

use Atannex\DomainRouting\Edge\MaintenanceStore;
use Atannex\DomainRouting\Resolution\SiteContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Global: domains:down {site} takes one site offline; the others keep serving. */
final class SiteMaintenance
{
    public function __construct(private readonly SiteContext $context, private readonly MaintenanceStore $store) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->context->current()?->name();

        if ($site === null || ($down = $this->store->payload($site)) === null) {
            return $next($request);
        }

        if ($request->is(...(array) config('domain-routing.maintenance.except', []))) {
            return $next($request);
        }

        $cookie = MaintenanceStore::cookie($site);
        $expected = $down['secret'] ? hash_hmac('sha256', $site.'|'.$down['secret'], (string) config('app.key')) : null;

        if ($expected !== null && trim($request->getPathInfo(), '/') === $down['secret']) {
            return (new RedirectResponse('/'))->withCookie(new Cookie($cookie, $expected, now()->addHours(12), '/', null, $request->isSecure(), true, false, 'lax'));
        }

        if ($expected !== null && hash_equals($expected, (string) $request->cookies->get($cookie, ''))) {
            return $next($request);
        }

        throw new HttpException((int) $down['status'], 'Service Unavailable', null, $down['retry'] ? ['Retry-After' => (string) $down['retry']] : []);
    }
}
