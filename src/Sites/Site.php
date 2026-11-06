<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Sites;

use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Hosts\HostPattern;
use Illuminate\Support\Env;

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
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromArray(string $name, array $config, string $environment): self
    {
        $hosts = self::hostsFor($name, $config['hosts'] ?? [], $environment);
        $dynamic = in_array(self::DYNAMIC, $hosts, true);
        $hosts = array_values(array_filter($hosts, fn (string $host) => $host !== self::DYNAMIC));
        $where = (array) ($config['where'] ?? []);

        $hosts = array_values(array_unique(array_map(fn (string $host) => HostPattern::parse($host, $where)->pattern, $hosts)));
        $canonical = isset($config['canonical']) ? HostPattern::parse((string) $config['canonical'], $where)->pattern : null;

        return new self(
            name: $name,
            hosts: $hosts,
            dynamic: $dynamic,
            canonical: $canonical,
            scheme: isset($config['scheme']) ? (string) $config['scheme'] : null,
            port: isset($config['port']) ? (int) $config['port'] : null,
            routes: array_values(array_map('strval', (array) ($config['routes'] ?? []))),
            middleware: array_values(array_map('strval', (array) ($config['middleware'] ?? []))),
            where: $where,
            session: (array) ($config['session'] ?? []),
            redirects: (array) ($config['redirects'] ?? []),
            robots: isset($config['robots']) ? (string) $config['robots'] : null,
            assetUrl: isset($config['asset_url']) ? rtrim((string) $config['asset_url'], '/') : null,
            meta: (array) ($config['meta'] ?? []),
        );
    }

    /** @return list<string> */
    private static function hostsFor(string $name, mixed $hosts, string $environment): array
    {
        $override = Env::get('DOMAIN_ROUTING_HOSTS_'.strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '_', $name)));

        if (is_string($override) && trim($override) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $override))));
        }

        $hosts = (array) $hosts;

        if (! array_is_list($hosts)) {
            $hosts = (array) ($hosts[$environment] ?? $hosts['*'] ?? []);
        }

        return array_values(array_map('strval', $hosts));
    }

    /** @return list<HostPattern> */
    public function patterns(): array
    {
        return array_map(fn (string $host) => HostPattern::parse($host, $this->where), $this->hosts);
    }

    public function canonicalPattern(): ?HostPattern
    {
        $host = $this->canonical ?? $this->hosts[0] ?? null;

        return $host === null ? null : HostPattern::parse($host, $this->where);
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
            [$middleware, ...$this->middleware], $this->where, $this->session, $this->redirects, $this->robots, $this->assetUrl, $this->meta);
    }

    /** Is the given host (already normalised) one of this site's exact hosts? */
    public function hasExactHost(string $host): bool
    {
        return in_array(HostNormalizer::normalize($host), array_map(fn (HostPattern $p) => $p->isExact() ? $p->pattern : null, $this->patterns()), true);
    }
}
