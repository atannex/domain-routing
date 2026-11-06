<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Controllers;

use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Atannex\DomainRouting\Resolution\SiteResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal On-demand TLS "ask" endpoint (Caddy, Traefik): 200 only for exact
 * hosts and verified dynamic hosts (tenant domains via tenancy), and for
 * {parameter} hosts of sites that opt in with 'tls' => true. Answers only the
 * allowed IPs, or callers presenting the shared secret.
 */
final class TlsAskController
{
    public function __invoke(Request $request, SiteResolver $resolver): Response
    {
        if (! $this->caller($request)) {
            return new Response('', 404);
        }

        $host = HostNormalizer::normalize((string) $request->query('domain', ''));
        $match = HostNormalizer::isValid($host) ? $resolver->resolve($host) : null;

        $approved = $match !== null && match ($match->source) {
            SiteMatch::EXACT, SiteMatch::PROVIDER => true,
            SiteMatch::PATTERN => $match->site->tls,
            default => false,
        };

        return new Response('', $approved ? 200 : 404);
    }

    private function caller(Request $request): bool
    {
        $secret = config('domain-routing.tls_ask.secret');

        if (is_string($secret) && $secret !== '' && hash_equals($secret, (string) $request->query('secret', $request->header('X-Tls-Ask-Secret', '')))) {
            return true;
        }

        $allow = (array) config('domain-routing.tls_ask.allow', []);

        return $allow !== [] && IpUtils::checkIp((string) $request->server->get('REMOTE_ADDR', ''), $allow);
    }
}
