<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Contracts;

use Atannex\DomainRouting\Resolution\SiteMatch;
use Atannex\DomainRouting\Sites\SiteRegistry;

/**
 * Resolves hosts the static index does not know (custom domains kept in a
 * database). $host is already normalised (lowercase, punycode, no port).
 * Return a match for a site that declares 'dynamic', or null. Do your own
 * caching; throwing counts as a miss. Verify your implementation with
 * Atannex\DomainRouting\Testing\HostProviderContract.
 */
interface HostProvider
{
    public function match(string $host, SiteRegistry $sites): ?SiteMatch;
}
