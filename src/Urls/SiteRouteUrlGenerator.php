<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Urls;

use Atannex\DomainRouting\Hosts\HostBuilder;
use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Routing\SiteValidator;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Illuminate\Routing\RouteUrlGenerator;

/** @internal Roots each route at its site's host when that differs from the request's. */
final class SiteRouteUrlGenerator extends RouteUrlGenerator
{
    private bool $absolute = true;

    private ?string $siteScheme = null;

    public function to($route, $parameters = [], $absolute = false)
    {
        $this->absolute = (bool) $absolute;
        $this->siteScheme = null;

        return parent::to($route, $parameters, $absolute);
    }

    protected function getRouteDomain($route, &$parameters)
    {
        $name = SiteValidator::siteOf($route->action);
        $sites = app(SiteRegistry::class);

        if ($name === null || $route->getDomain() || ! $sites->has($name)) {
            return parent::getRouteDomain($route, $parameters);
        }

        $site = $sites->get($name);
        $builder = app(HostBuilder::class);
        $resolved = $builder->resolve($site, $parameters);

        // Host values never leak into the query string, unless the path needs them too.
        foreach (array_diff($resolved->consumed, $route->parameterNames()) as $key) {
            unset($parameters[$key]);
        }

        if (! $this->absolute) {
            return null;
        }

        // Same host as the request: exactly what Laravel would build.
        if (HostNormalizer::normalize($this->request->getHost()) === $resolved->host && $this->portMatches($site)) {
            return null;
        }

        $this->siteScheme = $builder->scheme($site).'://';

        return $builder->rootFor($site, $resolved->host);
    }

    private function portMatches(\Atannex\DomainRouting\Sites\Site $site): bool
    {
        return $site->port === null || (int) $this->request->getPort() === $site->port;
    }

    protected function getRouteScheme($route)
    {
        if ($this->siteScheme !== null && ! $route->httpOnly() && ! $route->httpsOnly()) {
            return $this->siteScheme;
        }

        return parent::getRouteScheme($route);
    }
}
