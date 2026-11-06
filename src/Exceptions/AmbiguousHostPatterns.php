<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Exceptions;

final class AmbiguousHostPatterns extends DomainRoutingException
{
    public static function between(string $first, string $second, string $pattern): self
    {
        return new self("Sites [{$first}] and [{$second}] both claim [{$pattern}]; give each host to one site.");
    }
}
