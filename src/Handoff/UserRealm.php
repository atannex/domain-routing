<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Handoff;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;

/**
 * @internal Where a guard's users live (driver, database, host, model). User #1
 * in one tenant's database is someone else than user #1 in another's.
 */
final class UserRealm
{
    public static function of(SessionGuard $guard): string
    {
        $provider = $guard->getProvider();

        if (! $provider instanceof EloquentUserProvider) {
            return hash('sha256', get_class($provider));
        }

        $connection = $provider->createModel()->getConnection();

        return hash('sha256', implode('|', [$connection->getDriverName(), $connection->getDatabaseName(), (string) $connection->getConfig('host'), $provider->getModel()]));
    }
}
