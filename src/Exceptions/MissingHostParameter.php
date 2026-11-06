<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Exceptions;

final class MissingHostParameter extends DomainRoutingException
{
    public static function for(string $site, string $parameter): self
    {
        return new self("Site [{$site}] needs the host parameter [{$parameter}] to build a URL.");
    }

    public static function dynamic(string $site): self
    {
        return new self("Site [{$site}] has dynamic hosts and no HostParameters implementation returned one; pass the host context (e.g. a tenant) or generate the URL inside that site.");
    }

    public static function noHosts(string $site): self
    {
        return new self("Site [{$site}] has no hosts in this environment.");
    }
}
