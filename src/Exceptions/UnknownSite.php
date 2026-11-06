<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Exceptions;

final class UnknownSite extends DomainRoutingException
{
    public static function named(string $name): self
    {
        return new self("Site [{$name}] is not defined.");
    }
}
