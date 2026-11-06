<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Exceptions;

final class InvalidHostParameter extends DomainRoutingException
{
    public static function for(string $site, string $parameter, string $value): self
    {
        return new self("Host parameter [{$parameter}] of site [{$site}] does not accept [{$value}].");
    }
}
