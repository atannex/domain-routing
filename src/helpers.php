<?php

declare(strict_types=1);

use Atannex\DomainRouting\DomainRouting;
use Atannex\DomainRouting\Resolution\SiteMatch;

if (! function_exists('site_route')) {
    /** A URL to a named route of $site, on that site's host. @param array<array-key, mixed> $parameters */
    function site_route(string $site, string $name, array $parameters = [], bool $absolute = true): string
    {
        return app(DomainRouting::class)->route($site, $name, $parameters, $absolute);
    }
}

if (! function_exists('site_url')) {
    /** An absolute URL to a path on $site. @param array<array-key, mixed> $parameters */
    function site_url(string $site, string $path = '/', array $parameters = []): string
    {
        return app(DomainRouting::class)->url($site, $path, $parameters);
    }
}

if (! function_exists('site_asset')) {
    /** An asset URL for $site (its asset_url, or its own host). @param array<array-key, mixed> $parameters */
    function site_asset(string $site, string $path, array $parameters = []): string
    {
        return app(DomainRouting::class)->asset($site, $path, $parameters);
    }
}

if (! function_exists('current_site')) {
    function current_site(): ?SiteMatch
    {
        return app(DomainRouting::class)->current();
    }
}
