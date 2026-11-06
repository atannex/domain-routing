<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Attributes;

use Atannex\DomainRouting\Routing\SiteRoutes;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Atannex\Routing\Contracts\RouteModifier;
use Atannex\Routing\Definition\RouteDefinition;
use Attribute;

/**
 * Puts atannex/routing controllers on a site: #[Site('app')]. Adds the site's
 * middleware and name prefix, like Route::site(). Needs atannex/routing.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Site implements RouteModifier
{
    public function __construct(public string $name) {}

    public function modify(RouteDefinition $route): void
    {
        $site = app(SiteRegistry::class)->get($this->name);

        $route->meta['site'] = $site->name;
        $route->middleware(SiteRoutes::middleware($site));

        if (config('domain-routing.route_names', 'prefix') === 'prefix' && ! str_starts_with($route->namePrefix, $site->name.'.')) {
            $route->namePrefix = $site->name.'.'.$route->namePrefix;
        }
    }
}
