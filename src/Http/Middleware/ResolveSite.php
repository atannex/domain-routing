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
use Atannex\DomainRouting\Sites\Site;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Atannex\DomainRouting\Urls\SiteUrlGenerator;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\URL;
use Illuminate\View\FileViewFinder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global: request host → site. Runs after TrustProxies and before the session
 * starts. What it changes for the request (session cookie scope, forced root,
 * log context, site context) is put back in terminate(), after the response
 * and any after-response work, so long-running workers start clean.
 */
final class ResolveSite
{
    private const array SESSION_KEYS = ['cookie', 'domain', 'path', 'secure', 'same_site', 'partitioned'];

    private const string RESTORE = 'domain-routing.restore';

    public function __construct(
        private readonly SiteRegistry $sites,
        private readonly SiteResolver $resolver,
        private readonly SiteContext $context,
        private readonly Dispatcher $events,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Never inherit a site from an earlier request on this process.
        $this->context->set(null);

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

    public function terminate(Request $request, Response $response): void
    {
        $saved = $request->attributes->get(self::RESTORE);

        if (! is_array($saved)) {
            return;
        }

        config($saved['config']);
        URL::forceRootUrl($saved['root']);

        if ($saved['views'] !== null && app()->resolved('view') && ($finder = app('view')->getFinder()) instanceof FileViewFinder) {
            $finder->setPaths($saved['views']);
            // The finder caches resolved names; a site's view must not be served on the next site.
            $finder->flush();
        }
        Context::forget(['site', 'site_host', 'site_source']);

        if (app()->resolved('cookie')) {
            app('cookie')->setDefaultPathAndDomain(
                $saved['config']['session.path'], $saved['config']['session.domain'],
                (bool) $saved['config']['session.secure'], $saved['config']['session.same_site'],
            );
        }

        $this->context->set(null);
        $request->attributes->remove(self::RESTORE);
    }

    private function fallback(Request $request): SiteMatch|Response
    {
        $policy = (string) config('domain-routing.unmatched', 'abort');

        if (str_starts_with($policy, 'redirect:')) {
            return new RedirectResponse(substr($policy, 9), 302);
        }

        $default = config('domain-routing.default');

        // Only a site with a fixed host may stand in: links must never be rooted at the forged Host header.
        if ($policy === 'default' && is_string($default) && $this->sites->has($default)) {
            $site = $this->sites->get($default);
            $host = $site->canonicalHost();

            if ($host !== null && ! $site->dynamic) {
                return new SiteMatch($site, $host, [], SiteMatch::DEFAULT);
            }
        }

        throw new SiteNotFound(HostNormalizer::normalize($request->getHost()));
    }

    private function enter(Request $request, SiteMatch $match): void
    {
        $url = app('url');

        $keys = [...array_map(fn ($key) => 'session.'.$key, self::SESSION_KEYS), 'sanctum.stateful', ...array_keys($match->site->config)];

        $request->attributes->set(self::RESTORE, [
            'config' => array_combine($keys, array_map(fn ($key) => config($key), $keys)),
            'root' => $url instanceof SiteUrlGenerator ? $url->forcedRoot() : null,
            'views' => $match->site->views !== [] && app()->bound('view') && app('view')->getFinder() instanceof FileViewFinder ? app('view')->getFinder()->getPaths() : null,
        ]);

        $this->context->set($match);
        $request->attributes->set(SiteValidator::ATTRIBUTE, $match->name());
        $request->attributes->set(SiteValidator::SOURCE, $match->source);
        $request->attributes->set(SiteValidator::UNSITED, $this->unsitedAllowed($match));

        Context::add(['site' => $match->name(), 'site_host' => $match->host, 'site_source' => $match->source]);

        if ($match->source === SiteMatch::DEFAULT) {
            URL::forceRootUrl(app(HostBuilder::class)->rootFor($match->site, $match->host));
        }

        $this->applySession($match->site);
        $this->applySite($match);

        $this->events->dispatch(new SiteResolved($match));
    }

    /** Session and cookie scope for this site; cookies stay on the exact host unless the site shares them. */
    private function applySession(Site $site): void
    {
        $session = $site->session;

        if (config('domain-routing.isolate_sessions', true) && ! array_key_exists('domain', $session)) {
            $session['domain'] = null;
        }

        // A site that shares its cookie with a parent domain gets its own cookie name,
        // so sibling sites never receive (and load) its session.
        if (! empty($session['domain']) && ! array_key_exists('cookie', $session)) {
            $session['cookie'] = config('session.cookie').'_'.preg_replace('/[^a-z0-9_]/i', '_', $site->name);
        }

        foreach (self::SESSION_KEYS as $key) {
            if (array_key_exists($key, $session)) {
                config(['session.'.$key => $session[$key]]);
            }
        }

        // Remember-me and queued cookies take their defaults from the jar, not from config.
        if (app()->resolved('cookie')) {
            app('cookie')->setDefaultPathAndDomain(
                config('session.path'), config('session.domain'), (bool) config('session.secure'), config('session.same_site'),
            );
        }
    }

    /** Per-site config, view paths and Sanctum stateful host, for this request only. */
    private function applySite(SiteMatch $match): void
    {
        foreach ($match->site->config as $key => $value) {
            config([$key => $value]);
        }

        if ($match->site->views !== [] && app()->bound('view') && ($finder = app('view')->getFinder()) instanceof FileViewFinder) {

            foreach (array_reverse($match->site->views) as $path) {
                $finder->prependLocation($path);
            }

            $finder->flush();
        }

        // SPA sign-in on this host (tenant custom domains included) without listing every host in SANCTUM_STATEFUL_DOMAINS.
        if ($match->site->stateful && is_array(config('sanctum.stateful'))) {
            config(['sanctum.stateful' => array_values(array_unique([...config('sanctum.stateful'), $match->host]))]);
        }
    }

    /** Do routes outside any site answer here? Site setting first, then unsited_routes. */
    private function unsitedAllowed(SiteMatch $match): bool
    {
        if ($match->site->unsited !== null) {
            return $match->site->unsited;
        }

        return match ((string) config('domain-routing.unsited_routes', SiteValidator::EXACT)) {
            SiteValidator::EVERYWHERE => true,
            SiteValidator::NONE => false,
            SiteValidator::STATIC => $match->source !== SiteMatch::PROVIDER,
            default => in_array($match->source, [SiteMatch::EXACT, SiteMatch::DEFAULT], true),
        };
    }
}
