<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Integrations\Tenancy;

use Atannex\DomainRouting\Contracts\HostParameters;
use Atannex\DomainRouting\Hosts\ResolvedHost;
use Atannex\DomainRouting\Sites\Site;
use Atannex\Tenancy\Bootstrappers\UrlBootstrapper;
use Atannex\Tenancy\Contracts\Tenant;
use Atannex\Tenancy\Tenancy;

/** URLs to the tenant site: the given tenant (key, slug or model) or the current one, on its verified primary host. */
final class TenancyHostParameters implements HostParameters
{
    public function hostFor(Site $site, array $parameters): ?ResolvedHost
    {
        if ($site->name !== TenancyIntegration::site()) {
            return null;
        }

        $consumed = [];
        $tenant = $parameters['tenant'] ?? null;

        if ($tenant !== null) {
            $consumed = ['tenant'];
            $tenant = $tenant instanceof Tenant ? $tenant : app(Tenancy::class)->find((string) $tenant);
        } else {
            $tenant = app(Tenancy::class)->current();
        }

        if (! $tenant instanceof Tenant) {
            return null;
        }

        $host = UrlBootstrapper::hostFor($tenant);

        return $host === null ? null : new ResolvedHost($host, $consumed);
    }
}
