<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Events;

use Atannex\DomainRouting\Handoff\RejectionReason;

/** A cross-domain sign-in token was refused. The token itself is never included. */
final class HandoffRejected
{
    public function __construct(public readonly RejectionReason $reason, public readonly string $host) {}
}
