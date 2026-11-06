<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Contracts;

use Atannex\DomainRouting\Hosts\ResolvedHost;
use Atannex\DomainRouting\Sites\Site;

/**
 * Supplies the host for a URL to $site when the site's own patterns cannot
 * (dynamic hosts, or context that lives elsewhere, such as the current tenant).
 * Return null to pass. List the keys of $parameters you used in
 * ResolvedHost::$consumed so they do not end up in the query string.
 */
interface HostParameters
{
    /** @param array<array-key, mixed> $parameters */
    public function hostFor(Site $site, array $parameters): ?ResolvedHost;
}
