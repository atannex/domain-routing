<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Routing;

use Illuminate\Routing\RouteCollection;

/**
 * @internal Laravel keys routes by domain + URI, so "/" on two sites would
 * overwrite each other: key them by site too. Matching tries the current
 * site's own routes before routes that belong to no site, whatever order
 * they were registered in (routes/web.php loads before site route files).
 */
final class SiteAwareRouteCollection extends RouteCollection
{
    public static function from(RouteCollection $routes): self
    {
        $copy = new self;

        foreach ($routes->getRoutes() as $route) {
            $copy->add($route);
        }

        return $copy;
    }

    protected function addToCollections($route)
    {
        $site = SiteValidator::siteOf($route->action);
        $key = ($site === null ? '' : '@'.$site.'|').$route->getDomain().$route->uri();
        $methods = $route->methods();

        foreach ($methods as $method) {
            $this->routes[$method][$key] = $route;
        }

        $this->allRoutes[implode('|', $methods).$key] = $route;
    }

    protected function matchAgainstRoutes(array $routes, $request, $includingMethod = true)
    {
        $site = $request->attributes->get(SiteValidator::ATTRIBUTE);
        $tiers = [[], [], [], [], []];

        // Site routes, package endpoints, unsited routes, then the site's fallback before a global one.
        foreach ($routes as $route) {
            $owner = SiteValidator::siteOf($route->action);
            $rank = $owner === null ? 2 : ($owner === SiteValidator::ANY ? 1 : 0);
            $tiers[$route->isFallback ? ($rank === 2 ? 4 : 3) : $rank][] = $route;
        }

        if ($site === null) {
            $tiers = [[...$tiers[0], ...$tiers[1], ...$tiers[2]], [...$tiers[3], ...$tiers[4]]];
        }

        foreach ($tiers as $tier) {
            foreach ($tier as $route) {
                if ($route->matches($request, $includingMethod)) {
                    return $route;
                }
            }
        }

        return null;
    }
}
