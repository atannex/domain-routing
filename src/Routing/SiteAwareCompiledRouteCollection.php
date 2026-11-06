<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;

/**
 * @internal Laravel's cached-route matcher returns the first Symfony match and
 * never runs route validators. Keep that fast path when the route belongs to
 * the resolved site; otherwise match against that site's routes only.
 */
final class SiteAwareCompiledRouteCollection extends CompiledRouteCollection
{
    /** @var array<string, RouteCollection> */
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
        $route = parent::match($request);

        if (SiteValidator::allows(SiteValidator::siteOf($route->action), $request)) {
            return $route;
        }

        return $this->forSite((string) $request->attributes->get(SiteValidator::ATTRIBUTE))->match($request);
    }

    private function forSite(string $site): RouteCollection
    {
        if (isset($this->perSite[$site])) {
            return $this->perSite[$site];
        }

        $collection = new RouteCollection;

        foreach ($this->attributes as $attributes) {
            $owner = SiteValidator::siteOf((array) ($attributes['action'] ?? []));

            if ($owner === $site || ($owner === null && ! SiteValidator::$strict)) {
                $collection->add($this->newRoute($attributes));
            }
        }

        foreach ($this->routes->getRoutes() as $route) {
            $collection->add($route);
        }

        return $this->perSite[$site] = $collection;
    }
}
