<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Exceptions;

final class SitesFrozen extends DomainRoutingException
{
    public static function define(string $name): self
    {
        return new self("Site [{$name}] was defined after the application booted; define sites in a service provider's register() or boot().");
    }
}
