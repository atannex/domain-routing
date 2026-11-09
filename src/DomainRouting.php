<?php

declare(strict_types=1);

namespace Atannex\DomainRouting;

use Atannex\DomainRouting\Contracts\HostParameters;
use Atannex\DomainRouting\Contracts\HostProvider;
use Atannex\DomainRouting\Hosts\Extensions;
use Atannex\DomainRouting\Hosts\HostBuilder;
use Atannex\DomainRouting\Resolution\SiteContext;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Atannex\DomainRouting\Resolution\SiteResolver;
use Atannex\DomainRouting\Sites\Site;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Atannex\DomainRouting\Urls\SiteRoot;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\URL;

/** The package's front door (facade: DomainRouting). */
final class DomainRouting
{
    public function __construct(private readonly Application $app) {}

    /** Get a site, or define one when $definition is given (before the app boots). */
    public function site(string $name, ?array $definition = null): Site
    {
        $sites = $this->sites();

        return $definition === null ? $sites->get($name) : $sites->define($name, $definition, (string) $this->app->environment());
    }

    public function sites(): SiteRegistry
    {
        return $this->app->make(SiteRegistry::class);
    }

    public function current(): ?SiteMatch
    {
        return $this->app->make(SiteContext::class)->current();
    }

    public function is(string ...$names): bool
    {
        return $this->app->make(SiteContext::class)->is(...$names);
    }

    /** $port: the port asked of ("127.0.0.1:8001" works too); sites served on one port need it. */
    public function resolve(string $host, ?int $port = null): ?SiteMatch
    {
        return $this->app->make(SiteResolver::class)->resolve($host, $port);
    }

    /**
     * Run $callback as if inside $site (jobs, commands, mail previews).
     *
     * @template T
     *
     * @param  array<string, string>  $parameters
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(string|SiteMatch $site, Closure $callback, array $parameters = [], ?string $host = null): mixed
    {
        if (is_string($site)) {
            $definition = $this->site($site);
            $host ??= $this->app->make(HostBuilder::class)->resolve($definition, $parameters)->host;
            $site = new SiteMatch($definition, $host, $parameters, SiteMatch::EXACT);
        }

        $root = SiteRoot::current();
        SiteRoot::force($site);

        try {
            return $this->app->make(SiteContext::class)->run($site, $callback);
        } finally {
            URL::forceRootUrl($root);
        }
    }

    /** @param array<array-key, mixed> $parameters */
    public function route(string $site, string $name, array $parameters = [], bool $absolute = true): string
    {
        $routes = $this->app['router']->getRoutes();

        if (config('domain-routing.route_names', 'prefix') === 'prefix' && ! str_starts_with($name, $site.'.') && $routes->hasNamedRoute($site.'.'.$name)) {
            $name = $site.'.'.$name;
        }

        return $this->app['url']->route($name, $parameters, $absolute);
    }

    /** @param array<array-key, mixed> $parameters */
    public function url(string $site, string $path = '/', array $parameters = []): string
    {
        return $this->app->make(HostBuilder::class)->root($this->site($site), $parameters).'/'.ltrim($path, '/');
    }

    /** @param array<array-key, mixed> $parameters */
    public function asset(string $site, string $path, array $parameters = []): string
    {
        $definition = $this->site($site);

        if ($definition->assetUrl !== null) {
            return $definition->assetUrl.'/'.ltrim($path, '/');
        }

        return $this->url($site, $path, $parameters);
    }

    /** @param HostProvider|class-string<HostProvider> $provider */
    public function provider(HostProvider|string $provider): void
    {
        $this->app->make(Extensions::class)->addProvider($provider);
    }

    /** @param HostParameters|class-string<HostParameters> $parameters */
    public function hostParameters(HostParameters|string $parameters): void
    {
        $this->app->make(Extensions::class)->addParameters($parameters);
    }
}
