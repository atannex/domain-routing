<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @internal Laravel's cached-route matcher returns the first Symfony match and
 * never runs route validators. Keep that fast path when the route belongs to
 * the resolved site; otherwise match against that site's routes only.
 */
final class SiteAwareCompiledRouteCollection extends CompiledRouteCollection
{
    /** @var array<string, SiteAwareRouteCollection> */
    private array $perSite = [];

    public static function from(CompiledRouteCollection $routes): self
    {
        $copy = new self($routes->compiled, $routes->attributes);
        $copy->routes = $routes->routes;

        if ($routes->router !== null) {
            $copy->setRouter($routes->router);
        }

        if ($routes->container !== null) {
            $copy->setContainer($routes->container);
        }

        return $copy;
    }

    public function match(Request $request)
    {
        $site = $request->attributes->get(SiteValidator::ATTRIBUTE);

        if ($site === null) {
            $route = parent::match($request);

            if (SiteValidator::allows($route, $request)) {
                return $route;
            }

            return $this->forSite('')->match($request);
        }

        // Fast path only when the compiled match is the site's own route; an
        // unsited or other-site match may hide the site's own route or fallback.
        try {
            $route = parent::match($request);
        } catch (HttpException) {
            $route = null;
        }

        if ($route !== null && ! $route->isFallback && SiteValidator::siteOf($route->action) === $site) {
            return $route;
        }

        return $this->forSite((string) $site)->match($request);
    }

    private function forSite(string $site): SiteAwareRouteCollection
    {
        if (isset($this->perSite[$site])) {
            return $this->perSite[$site];
        }

        $collection = new SiteAwareRouteCollection;

        foreach ($this->attributes as $attributes) {
            $owner = SiteValidator::siteOf((array) ($attributes['action'] ?? []));

            // Candidates only; SiteValidator still decides for unsited routes.
            if ($owner === null || $owner === SiteValidator::ANY || $owner === $site) {
                $collection->add($this->newRoute($attributes));
            }
        }

        foreach ($this->routes->getRoutes() as $route) {
            $collection->add($route);
        }

        return $this->perSite[$site] = $collection;
    }
}
