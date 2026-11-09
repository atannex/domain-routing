<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Sites;

use Atannex\DomainRouting\Exceptions\InvalidHostPattern;
use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Hosts\HostPattern;

/** A named surface served on its own hosts. Immutable. */
final readonly class Site
{
    public const string DYNAMIC = 'dynamic';

    /**
     * @param  list<string>  $hosts
     * @param  list<string>  $routes
     * @param  list<string>  $middleware
     * @param  array<string, string>  $where
     * @param  array<string, mixed>  $session
     * @param  array<string, string|array{to: string, status?: int}>  $redirects
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $config
     * @param  list<string>  $views
     * @param  array<string, int>  $ports  exact host => the one port it is served on ("127.0.0.1:8001")
     */
    public function __construct(
        public string $name,
        public array $hosts = [],
        public bool $dynamic = false,
        public ?string $canonical = null,
        public ?string $scheme = null,
        public ?int $port = null,
        public array $routes = [],
        public array $middleware = [],
        public array $where = [],
        public array $session = [],
        public array $redirects = [],
        public ?string $robots = null,
        public ?string $assetUrl = null,
        public array $meta = [],
        public ?bool $unsited = null,
        public array $config = [],
        public array $views = [],
        public bool $stateful = false,
        public bool $tls = false,
        public bool $reserved = true,
        public array $ports = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, list<string>>  $overrides  site => hosts, from DOMAIN_ROUTING_HOSTS
     */
    public static function fromArray(string $name, array $config, string $environment, array $overrides = []): self
    {
        $hosts = $overrides[$name] ?? self::hostsFor($config['hosts'] ?? [], $environment);
        $dynamic = in_array(self::DYNAMIC, $hosts, true);
        $hosts = array_values(array_filter($hosts, fn (string $host) => $host !== self::DYNAMIC));
        $where = (array) ($config['where'] ?? []);
        [$hosts, $ports] = self::splitPorts($name, $hosts);

        $hosts = array_values(array_unique(array_map(fn (string $host) => HostPattern::parse($host, $where)->pattern, $hosts)));
        $ports = array_combine(array_map(fn (string $host) => HostPattern::parse($host, $where)->pattern, array_keys($ports)), $ports);
        $canonical = isset($config['canonical']) ? HostPattern::parse((string) $config['canonical'], $where)->pattern : null;

        return new self(
            name: $name,
            hosts: $hosts,
            dynamic: $dynamic,
            canonical: $canonical,
            scheme: isset($config['scheme']) ? (string) $config['scheme'] : null,
            // A host written with a port ("127.0.0.1:8001") is also where links to the site point.
            port: isset($config['port']) ? (int) $config['port'] : (count(array_unique($ports)) === 1 ? (int) reset($ports) : null),
            routes: array_values(array_map('strval', (array) ($config['routes'] ?? []))),
            middleware: array_values(array_map('strval', (array) ($config['middleware'] ?? []))),
            where: $where,
            session: (array) ($config['session'] ?? []),
            redirects: (array) ($config['redirects'] ?? []),
            robots: isset($config['robots']) ? (string) $config['robots'] : null,
            assetUrl: isset($config['asset_url']) ? rtrim((string) $config['asset_url'], '/') : null,
            meta: (array) ($config['meta'] ?? []),
            unsited: isset($config['unsited_routes']) ? (bool) $config['unsited_routes'] : null,
            config: (array) ($config['config'] ?? []),
            views: array_values(array_map('strval', (array) ($config['views'] ?? []))),
            stateful: (bool) ($config['stateful'] ?? false),
            tls: (bool) ($config['tls'] ?? false),
            reserved: (bool) ($config['reserved_labels'] ?? true),
            ports: $ports,
        );
    }

    /**
     * "127.0.0.1:8001" → host "127.0.0.1", served only on port 8001, so several
     * sites can share one address on different ports (one port per site in development).
     *
     * @param  list<string>  $hosts
     * @return array{0: list<string>, 1: array<string, int>}
     */
    private static function splitPorts(string $site, array $hosts): array
    {
        $plain = [];
        $ports = [];

        foreach ($hosts as $host) {
            $port = HostNormalizer::port($host);

            if ($port === null) {
                $plain[] = $host;

                continue;
            }

            $bare = (string) preg_replace('/:\d+$/', '', trim($host));

            if (str_contains($bare, '{')) {
                throw InvalidHostPattern::for($host, "a port is allowed on exact hosts only (site [{$site}])");
            }

            if ($port < 1 || $port > 65535) {
                throw InvalidHostPattern::for($host, 'the port must be between 1 and 65535');
            }

            $plain[] = $bare;
            $ports[$bare] = $port;
        }

        return [$plain, $ports];
    }

    /**
     * "app=a.com,b.com;marketing=x.com" → ['app' => ['a.com', 'b.com'], 'marketing' => ['x.com']].
     *
     * @return array<string, list<string>>
     */
    public static function parseOverrides(?string $value): array
    {
        $overrides = [];

        foreach (array_filter(array_map('trim', explode(';', (string) $value))) as $entry) {
            [$site, $hosts] = array_pad(explode('=', $entry, 2), 2, '');
            $hosts = array_values(array_filter(array_map('trim', explode(',', $hosts))));

            if (trim($site) !== '' && $hosts !== []) {
                $overrides[trim($site)] = $hosts;
            }
        }

        return $overrides;
    }

    /** @return list<string> */
    private static function hostsFor(mixed $hosts, string $environment): array
    {
        $hosts = (array) $hosts;

        if (! array_is_list($hosts)) {
            $hosts = (array) ($hosts[$environment] ?? $hosts['*'] ?? []);
        }

        return array_values(array_map('strval', $hosts));
    }

    /** @return list<HostPattern> */
    public function patterns(): array
    {
        return array_map(fn (string $host) => HostPattern::parse($host, $this->where, $this->reserved), $this->hosts);
    }

    public function canonicalPattern(): ?HostPattern
    {
        $host = $this->canonical ?? $this->hosts[0] ?? null;

        return $host === null ? null : HostPattern::parse($host, $this->where, $this->reserved);
    }

    /** The canonical host when it is a plain host (no parameters). */
    public function canonicalHost(): ?string
    {
        $pattern = $this->canonicalPattern();

        return $pattern?->isExact() ? $pattern->pattern : null;
    }

    /** @return list<string> */
    public function hostParameterNames(): array
    {
        $names = [];

        foreach ($this->patterns() as $pattern) {
            array_push($names, ...$pattern->parameters);
        }

        return array_values(array_unique($names));
    }

    public function withPrependedMiddleware(string $middleware): self
    {
        if (in_array($middleware, $this->middleware, true)) {
            return $this;
        }

        return new self($this->name, $this->hosts, $this->dynamic, $this->canonical, $this->scheme, $this->port, $this->routes,
            [$middleware, ...$this->middleware], $this->where, $this->session, $this->redirects, $this->robots, $this->assetUrl, $this->meta, $this->unsited, $this->config, $this->views, $this->stateful, $this->tls, $this->reserved, $this->ports);
    }

    /** Is the given host (already normalised) one of this site's exact hosts? */
    public function hasExactHost(string $host): bool
    {
        return in_array(HostNormalizer::normalize($host), array_map(fn (HostPattern $p) => $p->isExact() ? $p->pattern : null, $this->patterns()), true);
    }
}
