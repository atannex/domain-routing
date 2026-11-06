<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Events;

/** Every session of a user was signed out, on every domain. */
final class SessionStampRevoked
{
    public function __construct(public readonly string $guard, public readonly string $userId) {}
}
