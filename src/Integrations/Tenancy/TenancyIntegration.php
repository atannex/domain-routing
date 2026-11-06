<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Integrations\Tenancy;

use Atannex\DomainRouting\Hosts\Extensions;
use Atannex\Tenancy\Cache\PrefixedStore;
use Atannex\Tenancy\Tenancy;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;

/** Switches on when atannex/tenancy is installed. Identification stays tenancy's job. */
final class TenancyIntegration
{
    public static function available(): bool
    {
        return class_exists(Tenancy::class) && (bool) config('domain-routing.integrations.tenancy.enabled', true);
    }

    public static function site(): string
    {
        return (string) config('domain-routing.integrations.tenancy.site', 'tenant');
    }

    public static function register(Extensions $extensions): void
    {
        $extensions->addProvider(TenancyHostProvider::class);
        $extensions->addParameters(TenancyHostParameters::class);
    }

    /** The store underneath tenancy's per-tenant prefix, so one key means the same thing on every domain. */
    public static function unprefixed(Repository $repository): Repository
    {
        $store = $repository->getStore();

        return $store instanceof PrefixedStore ? new CacheRepository($store->inner()) : $repository;
    }

    /** Tenancy's identification runs first on the tenant site (before sessions and auth). */
    public static function middleware(string $site): ?string
    {
        if ($site !== self::site() || ! self::available()) {
            return null;
        }

        $middleware = config('domain-routing.integrations.tenancy.middleware', 'tenant');

        return is_string($middleware) && $middleware !== '' ? $middleware : null;
    }
}
