<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Handoff;

use Atannex\DomainRouting\Events\SessionStampRevoked;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;

/** A per-user stamp; bump it and every session holding the old one signs out, on every domain. */
final class SessionStamps
{
    public function current(string $guard, string $userId): string
    {
        return (string) $this->store()->get($this->key($guard, $userId), '0');
    }

    public function revokeAll(Authenticatable $user, ?string $guard = null): void
    {
        $guard ??= (string) config('auth.defaults.guard');
        $id = (string) $user->getAuthIdentifier();

        $this->store()->forever($this->key($guard, $id), bin2hex(random_bytes(8)));
        event(new SessionStampRevoked($guard, $id));
    }

    public static function sessionKey(string $guard): string
    {
        return 'domain_routing.stamp.'.$guard;
    }

    private function key(string $guard, string $userId): string
    {
        return 'domain-routing:stamp:'.$guard.':'.$userId;
    }

    private function store(): Repository
    {
        return cache()->store(config('domain-routing.stamps.store'));
    }
}
