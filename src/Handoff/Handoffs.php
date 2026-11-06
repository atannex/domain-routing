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
use Atannex\DomainRouting\Support\CentralCache;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Cross-domain sign-in, in three hops, like an OAuth "state" exchange:
 *
 *   A  Handoffs::to($url)           → B/_handoff/start?from=A&r=/path
 *   B  start: state cookie on B     → A/_handoff/issue?s=sha256(state)&to=B&r=/path
 *   A  issue: token for the signed-in user, bound to B's host and the state hash
 *                                    → B/_handoff?t=token
 *   B  redeem: cookie must match the state hash; sign in; regenerate the session.
 *
 * Only the browser that started on B can redeem, so a token captured or
 * minted by someone else is useless (no login CSRF). Tokens are 256-bit,
 * single use (atomic pull under a lock), 60 seconds, and only their SHA-256
 * is stored, in a cache that is never prefixed per tenant.
 */
final class Handoffs
{
    public const string COOKIE = 'domain_routing_handoff';

    public function __construct(
        private readonly SiteResolver $resolver,
        private readonly SiteContext $context,
        private readonly AuthFactory $auth,
        private readonly Dispatcher $events,
    ) {}

    /** On site A: send the signed-in user to $url on another site, signed in there too. */
    public function to(string $url, ?string $guard = null): RedirectResponse
    {
        [$origin, $path] = $this->target($url);
        $from = $this->origin(request());

        $query = http_build_query(array_filter(['from' => $from, 'r' => $path, 'g' => $guard]));

        return $this->noStore(new RedirectResponse($origin.'/'.$this->path('start').'?'.$query));
    }

    /** On site B: remember this browser, then ask the source site for a token. */
    public function start(Request $request): RedirectResponse|RejectionReason
    {
        $from = (string) $request->query('from', '');

        try {
            [$origin] = $this->target($from);
        } catch (InvalidHandoffTarget) {
            return $this->reject(RejectionReason::UnknownHost, $request);
        }

        $state = $this->random();
        $query = http_build_query(array_filter([
            's' => hash('sha256', $state),
            'to' => $this->origin($request),
            'r' => self::safePath((string) $request->query('r', '/')),
            'g' => $this->guardName($request->query('g')),
        ]));

        $response = $this->noStore(new RedirectResponse($origin.'/'.$this->path('issue').'?'.$query));
        $minutes = max(1, (int) ceil((int) config('domain-routing.handoff.state_ttl', 300) / 60));

        return $response->withCookie(new Cookie(self::COOKIE, $state, now()->addMinutes($minutes), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
    }

    /** On site A: mint the token for the signed-in user. Guests are sent on as guests. */
    public function issue(Request $request): RedirectResponse|RejectionReason
    {
        $state = (string) $request->query('s', '');
        $to = (string) $request->query('to', '');
        $path = self::safePath((string) $request->query('r', '/'));

        if (! preg_match('/^[a-f0-9]{64}$/', $state)) {
            return $this->reject(RejectionReason::Malformed, $request);
        }

        try {
            [$origin] = $this->target($to);
        } catch (InvalidHandoffTarget) {
            return $this->reject(RejectionReason::UnknownHost, $request);
        }

        $guardName = $this->guardName($request->query('g'));
        $guard = $guardName === null ? null : $this->auth->guard($guardName);

        if (! $guard instanceof SessionGuard || ! $guard->check()) {
            return $this->noStore(new RedirectResponse($origin.$path));
        }

        $token = $this->random();
        $user = (string) $guard->id();
        $host = HostNormalizer::normalize((string) parse_url($origin, PHP_URL_HOST));

        $this->store()->put($this->key($token), [
            'user' => $user,
            'guard' => $guardName,
            'host' => $host,
            'path' => $path,
            'state' => $state,
            'realm' => $this->realm($guard),
            'site' => $this->context->current()?->name(),
            'ip' => config('domain-routing.handoff.bind_ip') ? $request->ip() : null,
            'agent' => config('domain-routing.handoff.bind_user_agent') ? hash('sha256', (string) $request->userAgent()) : null,
        ], (int) config('domain-routing.handoff.ttl', 60));

        $this->events->dispatch(new HandoffIssued((string) $guardName, $user, $host, $this->context->current()?->name()));

        return $this->noStore(new RedirectResponse($origin.'/'.$this->path().'?t='.$token));
    }

    /** On site B: check everything, then sign in and regenerate the session. */
    public function redeem(Request $request): RedirectResponse|RejectionReason
    {
        $token = $request->query('t');

        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            return $this->reject(RejectionReason::Malformed, $request);
        }

        if (! $request->hasSession()) {
            return $this->reject(RejectionReason::NoSession, $request);
        }

        $store = $this->store();

        if (! $store->getStore() instanceof LockProvider) {
            return $this->reject(RejectionReason::StoreUnavailable, $request);
        }

        try {
            $payload = $store->getStore()->lock($this->key($token).':lock', 10)->get(fn () => $store->pull($this->key($token)));
        } catch (Throwable $e) {
            report($e);

            return $this->reject(RejectionReason::StoreUnavailable, $request);
        }

        if (! is_array($payload)) {
            return $this->reject(RejectionReason::ExpiredOrUsed, $request);
        }

        $state = (string) $request->cookies->get(self::COOKIE, '');

        if ($state === '' || ! hash_equals((string) $payload['state'], hash('sha256', $state))) {
            return $this->reject(RejectionReason::StateMismatch, $request);
        }

        if (! hash_equals((string) $payload['host'], HostNormalizer::normalize($request->getHost()))) {
            return $this->reject(RejectionReason::HostMismatch, $request);
        }

        if ($payload['ip'] !== null && ! hash_equals((string) $payload['ip'], (string) $request->ip())) {
            return $this->reject(RejectionReason::IpMismatch, $request);
        }

        if ($payload['agent'] !== null && ! hash_equals((string) $payload['agent'], hash('sha256', (string) $request->userAgent()))) {
            return $this->reject(RejectionReason::AgentMismatch, $request);
        }

        $guard = $this->guardName($payload['guard']) === null ? null : $this->auth->guard($payload['guard']);

        if (! $guard instanceof SessionGuard) {
            return $this->reject(RejectionReason::UnknownGuard, $request);
        }

        // Same users table on both sides, or the id would name someone else.
        if (! hash_equals((string) $payload['realm'], $this->realm($guard))) {
            return $this->reject(RejectionReason::RealmMismatch, $request);
        }

        if ($guard->check() && (string) $guard->id() !== (string) $payload['user']) {
            return $this->reject(RejectionReason::SessionConflict, $request);
        }

        $user = $guard->check() ? $guard->user() : $guard->getProvider()->retrieveById($payload['user']);

        if ($user === null) {
            return $this->reject(RejectionReason::UnknownUser, $request);
        }

        if (! $guard->check()) {
            $guard->login($user);
            $request->session()->regenerate();
        }

        $this->events->dispatch(new HandoffRedeemed((string) $payload['guard'], (string) $payload['user'], (string) $payload['host']));

        return $this->noStore(new RedirectResponse(self::safePath((string) $payload['path'])))->withoutCookie(self::COOKIE);
    }

    /** Only same-host relative paths: "/x", never "//evil.com", "/\evil.com" or control characters. */
    public static function safePath(string $path): string
    {
        return str_starts_with($path, '/') && ! str_starts_with($path, '//') && ! preg_match('/[\\\\\x00-\x1f\x7f]/', $path) ? $path : '/';
    }

    /** @return array{0: string, 1: string} [origin, path] of a URL on a host some site serves */
    private function target(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = HostNormalizer::normalize((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || $this->resolver->resolve($host) === null) {
            throw InvalidHandoffTarget::for($url);
        }

        if ($scheme === 'http' && app()->environment('production')) {
            $scheme = 'https';
        }

        $origin = $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = self::safePath('/'.ltrim((string) ($parts['path'] ?? '/'), '/').(isset($parts['query']) ? '?'.$parts['query'] : ''));

        return [$origin, $path];
    }

    private function origin(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    private function guardName(mixed $name): ?string
    {
        $name = is_string($name) && $name !== '' ? $name : (string) (config('domain-routing.handoff.guard') ?? config('auth.defaults.guard'));

        return is_array(config('auth.guards.'.$name)) ? $name : null;
    }

    /** Fingerprint of where the guard's users live (driver, database, host): tenant databases differ. */
    private function realm(SessionGuard $guard): string
    {
        $provider = $guard->getProvider();

        if (! $provider instanceof EloquentUserProvider) {
            return hash('sha256', get_class($provider));
        }

        $connection = $provider->createModel()->getConnection();

        return hash('sha256', implode('|', [$connection->getDriverName(), $connection->getDatabaseName(), (string) $connection->getConfig('host'), $provider->getModel()]));
    }

    private function reject(RejectionReason $reason, Request $request): RejectionReason
    {
        $host = HostNormalizer::normalize($request->getHost());
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

    private function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function path(string $step = ''): string
    {
        return trim((string) config('domain-routing.handoff.path', '_handoff'), '/').($step === '' ? '' : '/'.$step);
    }

    private function key(string $token): string
    {
        return 'domain-routing:handoff:'.hash('sha256', $token);
    }

    private function store(): Repository
    {
        return CentralCache::store(config('domain-routing.handoff.store'));
    }
}
