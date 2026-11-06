<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Handoff;

enum RejectionReason: string
{
    case Malformed = 'malformed';
    case ExpiredOrUsed = 'expired_or_used';
    case HostMismatch = 'host_mismatch';
    case IpMismatch = 'ip_mismatch';
    case AgentMismatch = 'agent_mismatch';
    case UnknownGuard = 'unknown_guard';
    case UnknownUser = 'unknown_user';
    case StoreUnavailable = 'store_unavailable';
}
