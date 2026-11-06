<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Handoff;

use Atannex\DomainRouting\Events\HandoffIssued;
use Atannex\DomainRouting\Events\HandoffRedeemed;
use Atannex\DomainRouting\Events\HandoffRejected;
use Atannex\DomainRouting\Exceptions\InvalidHandoffTarget;
use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Resolution\SiteContext;
use Atannex\DomainRouting\Resolution\SiteResolver;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Carries a signed-in user from one domain to another: a single-use,
 * short-lived token bound to the user, the guard and the target host.
 * Only the token's SHA-256 is stored.
 */
final class Handoffs
{
    public function __construct(
        private readonly SiteResolver $resolver,
        private readonly SiteContext $context,
        private readonly AuthFactory $auth,
        private readonly Dispatcher $events,
    ) {}

    /** A redirect that signs $user in on the domain of $url and lands on its path. */
    public function to(Authenticatable $user, string $url, ?string $guard = null): RedirectResponse
    {
        return $this->noStore(new RedirectResponse($this->url($user, $url, $guard)));
    }

    public function url(Authenticatable $user, string $url, ?string $guard = null): string
    {
        $guard ??= (string) config('auth.defaults.guard');
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = HostNormalizer::normalize((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $this->resolver->resolve($host) === null) {
            throw InvalidHandoffTarget::for($url);
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $userId = (string) $user->getAuthIdentifier();

        $this->store()->put($this->key($token), [
            'user' => $userId,
            'guard' => $guard,
            'host' => $host,
            'path' => $path,
            'site' => $this->context->current()?->name(),
            'ip' => config('domain-routing.handoff.bind_ip') ? request()->ip() : null,
            'issued' => time(),
        ], (int) config('domain-routing.handoff.ttl', 60));

        $this->events->dispatch(new HandoffIssued($guard, $userId, $host, $this->context->current()?->name()));

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return $scheme.'://'.$host.$port.'/'.trim((string) config('domain-routing.handoff.path', '_handoff'), '/').'?t='.$token;
    }

    /** Redeem the token on this request's host: sign in, regenerate the session, redirect. */
    public function redeem(Request $request): RedirectResponse|RejectionReason
    {
        $token = $request->query('t');
        $host = HostNormalizer::normalize($request->getHost());

        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return $this->reject(RejectionReason::Malformed, $host);
        }

        $store = $this->store();

        if (! $store->getStore() instanceof LockProvider) {
            return $this->reject(RejectionReason::StoreUnavailable, $host);
        }

        try {
            $payload = $store->getStore()->lock($this->key($token).':lock', 10)->get(fn () => $store->pull($this->key($token)));
        } catch (Throwable $e) {
            report($e);

            return $this->reject(RejectionReason::StoreUnavailable, $host);
        }

        if (! is_array($payload)) {
            return $this->reject(RejectionReason::ExpiredOrUsed, $host);
        }

        if (! hash_equals((string) $payload['host'], $host)) {
            return $this->reject(RejectionReason::HostMismatch, $host);
        }

        if ($payload['ip'] !== null && ! hash_equals((string) $payload['ip'], (string) $request->ip())) {
            return $this->reject(RejectionReason::IpMismatch, $host);
        }

        if (! is_array(config('auth.guards.'.$payload['guard']))) {
            return $this->reject(RejectionReason::UnknownGuard, $host);
        }

        $guard = $this->auth->guard($payload['guard']);

        if (! $guard instanceof SessionGuard) {
            return $this->reject(RejectionReason::UnknownGuard, $host);
        }

        $user = $guard->getProvider()->retrieveById($payload['user']);

        if ($user === null) {
            return $this->reject(RejectionReason::UnknownUser, $host);
        }

        $guard->login($user);
        $request->session()->regenerate();

        $this->events->dispatch(new HandoffRedeemed($payload['guard'], (string) $payload['user'], $host));

        return $this->noStore(new RedirectResponse(self::safePath((string) $payload['path'])));
    }

    /** Only same-host relative paths: "/x", never "//evil.com" or "/\evil.com". */
    public static function safePath(string $path): string
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! str_contains($path, '\\') ? $path : '/';
    }

    private function reject(RejectionReason $reason, string $host): RejectionReason
    {
        logger()->warning('[domain-routing] handoff rejected', ['reason' => $reason->value, 'host' => $host]);
        $this->events->dispatch(new HandoffRejected($reason, $host));

        return $reason;
    }

    private function noStore(RedirectResponse $response): RedirectResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function key(string $token): string
    {
        return 'domain-routing:handoff:'.hash('sha256', $token);
    }

    private function store(): Repository
    {
        return cache()->store(config('domain-routing.handoff.store'));
    }
}
