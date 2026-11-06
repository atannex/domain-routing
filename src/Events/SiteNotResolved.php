<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Events;

/** No site serves the request host. */
final class SiteNotResolved
{
    public function __construct(public readonly string $host) {}
}
