<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Events;

/** A request was sent on to its canonical URL. */
final class CanonicalRedirected
{
    public function __construct(public readonly string $from, public readonly string $to, public readonly int $status) {}
}
