<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Integrations\Tenancy;

use Atannex\DomainRouting\Hosts\Extensions;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Atannex\Tenancy\Tenancy;

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

    public static function register(Extensions $extensions, SiteRegistry $sites): void
    {
        $extensions->addProvider(TenancyHostProvider::class);
        $extensions->addParameters(TenancyHostParameters::class);

        $middleware = config('domain-routing.integrations.tenancy.middleware', 'tenant');

        if (is_string($middleware) && $middleware !== '' && $sites->has(self::site())) {
            $sites->replace($sites->get(self::site())->withPrependedMiddleware($middleware));
        }
    }
}
