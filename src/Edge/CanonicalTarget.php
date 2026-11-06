<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Edge;

use Atannex\DomainRouting\Hosts\HostBuilder;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Illuminate\Http\Request;

/** @internal The one URL a request should be on (scheme, host, redirect map), or null when it already is. */
final class CanonicalTarget
{
    public function __construct(private readonly SiteRegistry $sites, private readonly HostBuilder $hosts) {}

    /** @return array{0: string, 1: int}|null [url, status] */
    public function for(Request $request, SiteMatch $match): ?array
    {
        $config = (array) config('domain-routing.canonical', []);
        $status = (int) ($config['status'] ?? 301);
        $path = $request->getPathInfo();
        $query = $request->getQueryString();

        $map = $match->site->redirects + (array) ($config['redirects'] ?? []);
        $key = '/'.ltrim($path, '/');

        if (isset($map[$key])) {
            $entry = is_array($map[$key]) ? $map[$key] : ['to' => $map[$key]];
            $status = (int) ($entry['status'] ?? $status);
            $to = (string) $entry['to'];

            if (preg_match('#^https?://#i', $to)) {
                return [$to, $status];
            }

            $path = '/'.ltrim($to, '/');
        }

        $host = $this->host($match, (string) ($config['www'] ?? ''));
        $scheme = $request->getScheme();

        if (($config['https'] ?? true) && $this->hosts->scheme($match->site) === 'https') {
            $scheme = 'https';
        }

        $port = $match->site->port;

        if ($port === null && $host === $match->host && ! in_array((int) $request->getPort(), [80, 443], true)) {
            $port = (int) $request->getPort();
        }

        $target = $this->url($scheme, $host, $port, $path, $query);
        // The raw host, so "Example.com." is sent on to "example.com" (a separate cookie origin otherwise).
        $raw = (string) preg_replace('/:\d+$/', '', (string) $request->server->get('HTTP_HOST', $request->getHost()));
        $current = $this->url($request->getScheme(), $raw, in_array((int) $request->getPort(), [80, 443], true) ? null : (int) $request->getPort(), $request->getPathInfo(), $query);

        return $target === $current ? null : [$target, $status];
    }

    private function host(SiteMatch $match, string $www): string
    {
        if ($match->canonicalHost !== null && $match->canonicalHost !== '') {
            return $match->canonicalHost;
        }

        $site = $match->site;

        if ($match->source === SiteMatch::EXACT && $site->canonical !== null && ($canonical = $site->canonicalHost()) !== null) {
            return $canonical;
        }

        if ($match->source === SiteMatch::PROVIDER || $site->canonical !== null || ! in_array($www, ['strip', 'add'], true)) {
            return $match->host;
        }

        $toggled = $www === 'strip'
            ? (str_starts_with($match->host, 'www.') ? substr($match->host, 4) : null)
            : (str_starts_with($match->host, 'www.') ? null : 'www.'.$match->host);

        if ($toggled !== null && ($this->sites->index()->match($toggled)['site'] ?? null) === $site->name) {
            return $toggled;
        }

        return $match->host;
    }

    private function url(string $scheme, string $host, ?int $port, string $path, ?string $query): string
    {
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.$host.($port !== null && $port !== $default ? ':'.$port : '').$path.($query ? '?'.$query : '');
    }
}
