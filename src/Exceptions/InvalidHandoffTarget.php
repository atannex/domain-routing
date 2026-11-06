<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Exceptions;

final class InvalidHandoffTarget extends DomainRoutingException
{
    public static function for(string $url): self
    {
        return new self("Cannot hand a session off to [{$url}]: the host is not served by any site.");
    }
}
