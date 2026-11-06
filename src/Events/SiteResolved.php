<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Events;

use Atannex\DomainRouting\Resolution\SiteMatch;

/** A request host resolved to a site. */
final class SiteResolved
{
    public function __construct(public readonly SiteMatch $match) {}
}
