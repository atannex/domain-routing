<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Integrations\Tenancy;

use Atannex\DomainRouting\Contracts\HostProvider;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Atannex\Tenancy\Domains\AliasRedirect;
use Illuminate\Http\Request;

/** Verified tenant domains and {slug}.{central domain} → the tenant site. Uses tenancy's own resolvers and cache. */
final class TenancyHostProvider implements HostProvider
{
    public function match(string $host, SiteRegistry $sites): ?SiteMatch
    {
        $name = TenancyIntegration::site();

        if (! $sites->has($name) || ! $sites->get($name)->dynamic) {
            return null;
        }

        $request = Request::create('http://'.$host.'/');
        $resolvers = array_intersect((array) config('tenancy.identification.resolvers', ['domain', 'subdomain']), ['domain', 'subdomain']);

        foreach ($resolvers as $resolver) {
            $class = config('tenancy.identification.drivers.'.$resolver);

            if (! is_string($class) || ! class_exists($class)) {
                continue;
            }

            $tenant = app($class)->resolve($request);

            if ($tenant === null) {
                continue;
            }

            $canonical = $resolver === 'domain' && config('tenancy.domains.redirect_aliases', true) ? AliasRedirect::target($host) : null;

            return new SiteMatch($sites->get($name), $host, ['tenant' => $tenant->getTenantSlug()], SiteMatch::PROVIDER, $canonical);
        }

        return null;
    }
}
