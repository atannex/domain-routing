<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Events;

/** A cross-domain sign-in token was issued. */
final class HandoffIssued
{
    public function __construct(public readonly string $guard, public readonly string $userId, public readonly string $targetHost, public readonly ?string $sourceSite) {}
}
