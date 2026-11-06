<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Events;

/** A cross-domain sign-in token was used. */
final class HandoffRedeemed
{
    public function __construct(public readonly string $guard, public readonly string $userId, public readonly string $host) {}
}
