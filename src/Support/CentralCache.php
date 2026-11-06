<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Support;

use Atannex\DomainRouting\Integrations\Tenancy\TenancyIntegration;
use Illuminate\Contracts\Cache\Repository;

/** @internal A cache store that is the same on every domain: never prefixed per tenant. */
final class CentralCache
{
    public static function store(?string $name): Repository
    {
        $store = cache()->store($name);

        return TenancyIntegration::available() ? TenancyIntegration::unprefixed($store) : $store;
    }
}
