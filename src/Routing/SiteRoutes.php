<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Routing;

use Atannex\DomainRouting\Integrations\Tenancy\TenancyIntegration;
use Atannex\DomainRouting\Sites\Site;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Closure;
use Illuminate\Routing\Router;

/** @internal Route::site('app', fn () => …) and the route files named in config. */
final class SiteRoutes
{
    /** @return array<string, mixed> */
    public static function attributes(Site $site): array
    {
        $attributes = ['site' => $site->name, 'middleware' => self::middleware($site)];

        if (config('domain-routing.route_names', 'prefix') === 'prefix') {
            $attributes['as'] = $site->name.'.';
        }

        return $attributes;
    }

    /** @return list<string> the site's middleware, with integration middleware in front */
    public static function middleware(Site $site): array
    {
        $middleware = $site->middleware;
        $tenancy = TenancyIntegration::middleware($site->name);

        if ($tenancy !== null && ! in_array($tenancy, $middleware, true)) {
            array_unshift($middleware, $tenancy);
        }

        return $middleware;
    }

    public static function macro(): void
    {
        Router::macro('site', function (string $name, Closure|string|array $routes): void {
            /** @var Router $this */
            $site = app(SiteRegistry::class)->get($name);

            foreach ((array) (is_array($routes) ? $routes : [$routes]) as $group) {
                $this->group(SiteRoutes::attributes($site), $group);
            }
        });
    }

    public static function load(Router $router, SiteRegistry $sites): void
    {
        foreach ($sites->all() as $site) {
            foreach ($site->routes as $file) {
                $router->group(self::attributes($site), $file);
            }
        }
    }
}
