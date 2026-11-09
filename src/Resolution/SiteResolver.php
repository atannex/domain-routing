<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Resolution;

use Atannex\DomainRouting\Exceptions\ProviderContractViolation;
use Atannex\DomainRouting\Hosts\Extensions;
use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Throwable;

/** @internal Host (and port) → site: static index first, then HostProviders. No HTTP here. */
final class SiteResolver
{
    public function __construct(private readonly SiteRegistry $sites, private readonly Extensions $extensions) {}

    /** $port: the port asked of; read from "host:port" when not given. A site served on that port wins. */
    public function resolve(string $host, ?int $port = null): ?SiteMatch
    {
        $port ??= HostNormalizer::port($host);
        $host = HostNormalizer::normalize($host);

        if (! HostNormalizer::isValid($host)) {
            return null;
        }

        if ($hit = $this->sites->index()->match($host, $port)) {
            return new SiteMatch($this->sites->get($hit['site']), $host, $hit['parameters'], $hit['kind']);
        }

        if (! $this->sites->hasDynamic()) {
            return null;
        }

        foreach ($this->extensions->providers() as $provider) {
            try {
                $match = $provider->match($host, $this->sites);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            if ($match === null) {
                continue;
            }

            if (! $match->site->dynamic) {
                report(ProviderContractViolation::nonDynamic($provider, $match->name()));

                continue;
            }

            return new SiteMatch($match->site, $host, $match->parameters, SiteMatch::PROVIDER, $match->canonicalHost);
        }

        return null;
    }
}
