<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Middleware;

use Atannex\DomainRouting\Events\SiteNotResolved;
use Atannex\DomainRouting\Events\SiteResolved;
use Atannex\DomainRouting\Exceptions\SiteNotFound;
use Atannex\DomainRouting\Hosts\HostBuilder;
use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Resolution\SiteContext;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Atannex\DomainRouting\Resolution\SiteResolver;
use Atannex\DomainRouting\Routing\SiteValidator;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/** Global: request host → site. Runs after TrustProxies and before the session starts. */
final class ResolveSite
{
    public function __construct(
        private readonly SiteRegistry $sites,
        private readonly SiteResolver $resolver,
        private readonly SiteContext $context,
        private readonly Dispatcher $events,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->sites->isEmpty() || $request->is(...(array) config('domain-routing.except', []))) {
            return $next($request);
        }

        $match = $this->resolver->resolve($request->getHost());

        if ($match === null) {
            $this->events->dispatch(new SiteNotResolved(HostNormalizer::normalize($request->getHost())));
            $match = $this->fallback($request);

            if ($match instanceof Response) {
                return $match;
            }
        }

        $this->enter($request, $match);

        $response = $next($request);

        if (config('domain-routing.debug_header')) {
            $response->headers->set('X-Site', $match->name());
        }

        return $response;
    }

    private function fallback(Request $request): SiteMatch|Response
    {
        $policy = (string) config('domain-routing.unmatched', 'abort');

        if (str_starts_with($policy, 'redirect:')) {
            return new RedirectResponse(substr($policy, 9), 302);
        }

        $default = config('domain-routing.default');

        if ($policy === 'default' && is_string($default) && $this->sites->has($default)) {
            $site = $this->sites->get($default);

            return new SiteMatch($site, $site->canonicalHost() ?? HostNormalizer::normalize($request->getHost()), [], SiteMatch::DEFAULT);
        }

        throw new SiteNotFound(HostNormalizer::normalize($request->getHost()));
    }

    private function enter(Request $request, SiteMatch $match): void
    {
        $this->context->set($match);
        $request->attributes->set(SiteValidator::ATTRIBUTE, $match->name());

        Log::withContext(['site' => $match->name(), 'site_host' => $match->host, 'site_source' => $match->source]);

        // An unknown Host header must never become the root of generated links.
        if ($match->source === SiteMatch::DEFAULT && $match->site->canonicalHost() !== null) {
            URL::forceRootUrl(app(HostBuilder::class)->rootFor($match->site, $match->host));
        }

        foreach (['cookie', 'domain', 'path', 'secure', 'same_site'] as $key) {
            if (array_key_exists($key, $match->site->session)) {
                config(['session.'.$key => $match->site->session[$key]]);
            }
        }

        $this->events->dispatch(new SiteResolved($match));
    }
}
