<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Handoff;

use Atannex\DomainRouting\Events\SessionStampRevoked;
use Atannex\DomainRouting\Support\CentralCache;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;

/**
 * A per-user stamp, kept in a store that is the same on every domain. Bump it
 * and every session holding the old one signs out, on every site and tenant;
 * the remember-me token is cycled too, so "remember me" cookies stop working.
 */
final class SessionStamps
{
    public function current(string $guard, string $userId): string
    {
        return (string) $this->store()->get($this->key($guard, $userId), '0');
    }

    /** The realm (user table) is part of the key: user #1 of one tenant database is not user #1 of another. */
    private function realm(string $guard): string
    {
        $instance = auth()->guard($guard);

        return $instance instanceof SessionGuard ? UserRealm::of($instance) : 'none';
    }

    public function revokeAll(Authenticatable $user, ?string $guard = null): void
    {
        $guard ??= (string) config('auth.defaults.guard');
        $id = (string) $user->getAuthIdentifier();

        $this->store()->forever($this->key($guard, $id), bin2hex(random_bytes(8)));

        $instance = auth()->guard($guard);

        if ($instance instanceof SessionGuard && $user->getRememberTokenName() !== '') {
            $instance->getProvider()->updateRememberToken($user, Str::random(60));
        }

        event(new SessionStampRevoked($guard, $id));
    }

    /** Write the current stamp into the session at login (Login event). */
    public function stamp(string $guard, Authenticatable $user): void
    {
        if (app()->bound('session.store') && app('session.store')->isStarted()) {
            app('session.store')->put(self::sessionKey($guard), $this->current($guard, (string) $user->getAuthIdentifier()));
        }
    }

    public static function sessionKey(string $guard): string
    {
        return 'domain_routing.stamp.'.$guard;
    }

    private function key(string $guard, string $userId): string
    {
        return 'domain-routing:stamp:'.$this->realm($guard).':'.$guard.':'.$userId;
    }

    private function store(): Repository
    {
        return CentralCache::store(config('domain-routing.stamps.store'));
    }
}
