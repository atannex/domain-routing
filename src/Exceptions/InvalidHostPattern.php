<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Exceptions;

final class InvalidHostPattern extends DomainRoutingException
{
    public static function for(string $pattern, string $reason): self
    {
        return new self("Host pattern [{$pattern}] is invalid: {$reason}.");
    }
}
