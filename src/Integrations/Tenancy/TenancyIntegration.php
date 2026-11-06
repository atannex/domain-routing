<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Integrations\Tenancy;

use Atannex\DomainRouting\Hosts\Extensions;
use Atannex\Tenancy\Bootstrappers\UrlBootstrapper;
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

    /**
     * Middleware put in front of a site's own: tenancy's identification on the
     * tenant site (before sessions and auth), and optionally tenancy's
     * `central` guard on the central site.
     */
    public static function middleware(string $site): ?string
    {
        if (! self::available()) {
            return null;
        }

        $key = match ($site) {
            self::site() => 'middleware',
            (string) config('domain-routing.integrations.tenancy.central_site') => 'central_middleware',
            default => null,
        };

        $middleware = $key === null ? null : config('domain-routing.integrations.tenancy.'.$key);

        return is_string($middleware) && $middleware !== '' ? $middleware : null;
    }

    /** tenancy's scheme for tenant links (tenancy.url.scheme, the request's, else https), for the tenant site. */
    public static function scheme(string $site): ?string
    {
        return $site === self::site() && self::available() ? rtrim(UrlBootstrapper::scheme(), ':/') : null;
    }

    /** Tenancy's central domains, for the "tenancy:central" host token. @return list<string> */
    public static function centralHosts(): array
    {
        return array_values(array_map('strval', (array) config('tenancy.identification.central_domains', [])));
    }

    /** Tenancy routes that belong on tenant domains too (impersonation, invitation links sent from a tenant). @return list<string> */
    public static function sharedRoutes(): array
    {
        if (! config('tenancy.people.routes.enabled', false)) {
            return [];
        }

        $prefix = trim((string) config('tenancy.people.routes.prefix', 'tenancy'), '/');

        return [$prefix.'/impersonate/*', $prefix.'/invitations/*'];
    }

    /** Tenancy endpoints called without a site's host (the TLS ask from Caddy on localhost). @return list<string> */
    public static function exceptPaths(): array
    {
        return config('tenancy.domains.tls_ask.enabled') ? [trim((string) config('tenancy.domains.tls_ask.path'), '/')] : [];
    }

    /** Tenancy's reserved slugs are never a {parameter} either. @return list<string> */
    public static function reservedLabels(): array
    {
        return array_values(array_map('strval', (array) config('tenancy.reserved_slugs', [])));
    }
}
