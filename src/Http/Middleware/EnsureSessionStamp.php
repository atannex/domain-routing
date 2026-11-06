<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Middleware;

use Atannex\DomainRouting\Handoff\SessionStamps;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** In the web group: signs out sessions whose stamp was revoked ("sign out everywhere"). */
final class EnsureSessionStamp
{
    public function __construct(private readonly SessionStamps $stamps) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        foreach ((array) config('domain-routing.stamps.guards', ['web']) as $name) {
            $guard = auth()->guard($name);

            if (! $guard instanceof SessionGuard || ! $guard->check()) {
                continue;
            }

            $current = $this->stamps->current($name, (string) $guard->id());
            $key = SessionStamps::sessionKey($name);
            $held = $request->session()->get($key);

            if ($held === null) {
                $request->session()->put($key, $current);
            } elseif (! hash_equals($current, (string) $held)) {
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return $next($request);
    }
}
