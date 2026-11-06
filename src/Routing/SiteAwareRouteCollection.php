<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Routing;

use Illuminate\Routing\RouteCollection;

/**
 * @internal Laravel keys routes by domain + URI, so "/" on two sites would
 * overwrite each other. Key them by site too; the SiteValidator picks one.
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
}
