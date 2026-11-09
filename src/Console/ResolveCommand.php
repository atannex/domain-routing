<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Console;

use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Resolution\SiteResolver;
use Illuminate\Console\Command;

final class ResolveCommand extends Command
{
    protected $signature = 'domains:resolve {host : The host to explain (host:port for a site served on one port)}';

    protected $description = 'Explain which site serves a host, and why';

    public function handle(SiteResolver $resolver): int
    {
        $raw = (string) $this->argument('host');
        $host = HostNormalizer::normalize($raw);

        if (! HostNormalizer::isValid($host)) {
            $this->error("[{$raw}] is not a valid host name.");

            return self::FAILURE;
        }

        $port = HostNormalizer::port($raw);
        $match = $resolver->resolve($host, $port);

        if ($match === null) {
            $this->warn("No site serves [{$host}]. Policy: ".config('domain-routing.unmatched', 'abort').'.');

            return self::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['Host', $host.($port !== null ? ':'.$port : '')],
            ['Site', $match->name()],
            ['Matched by', match ($match->source) {
                'exact' => 'exact host',
                'pattern' => 'host pattern',
                'provider' => 'host provider (dynamic)',
                default => $match->source,
            }],
            ['Parameters', $match->parameters === [] ? '—' : (string) json_encode($match->parameters)],
            ['Canonical host', $match->canonicalHost ?? $match->site->canonical ?? '—'],
            ['Middleware', implode(', ', $match->site->middleware) ?: '—'],
        ]);

        return self::SUCCESS;
    }
}
