<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Hosts;

final readonly class ResolvedHost
{
    /** @param list<string> $consumed */
    public function __construct(public string $host, public array $consumed = []) {}
}
