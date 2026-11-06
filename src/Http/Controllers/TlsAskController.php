<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Controllers;

use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Resolution\SiteResolver;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal On-demand TLS "ask" endpoint (Caddy, Traefik): 200 for a host some
 * site serves, static or dynamic (verified tenant domains via tenancy), else 404.
 */
final class TlsAskController
{
    public function __invoke(Request $request, SiteResolver $resolver): Response
    {
        $host = HostNormalizer::normalize((string) $request->query('domain', ''));

        if (! HostNormalizer::isValid($host) || $resolver->resolve($host) === null) {
            return new Response('', 404);
        }

        return new Response('', 200);
    }
}
