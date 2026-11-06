<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Handoff;

enum RejectionReason: string
{
    case Malformed = 'malformed';
    case UnknownHost = 'unknown_host';
    case ExpiredOrUsed = 'expired_or_used';
    case StateMismatch = 'state_mismatch';
    case HostMismatch = 'host_mismatch';
    case IpMismatch = 'ip_mismatch';
    case AgentMismatch = 'agent_mismatch';
    case UnknownGuard = 'unknown_guard';
    case UnknownUser = 'unknown_user';
    case RealmMismatch = 'realm_mismatch';
    case SessionConflict = 'session_conflict';
    case NoSession = 'no_session';
    case StoreUnavailable = 'store_unavailable';
}
