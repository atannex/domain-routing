<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Exceptions;

final class ProviderContractViolation extends DomainRoutingException
{
    public static function nonDynamic(object $provider, string $site): self
    {
        return new self(get_class($provider)." returned site [{$site}], which does not declare 'dynamic' hosts.");
    }
}
