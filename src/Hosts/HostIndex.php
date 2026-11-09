<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Hosts;

use Atannex\DomainRouting\Exceptions\AmbiguousHostPatterns;
use Atannex\DomainRouting\Sites\Site;

/**
 * @internal Exact hosts in a hash map; parameterised patterns most-literal first.
 * A host served on one port is keyed "host:port", so sites can share an address.
 */
final class HostIndex
{
    /**
     * @param  array<string, string>  $exact  host => site
     * @param  list<array{0: string, 1: string, 2: array<string, string>, 3?: bool}>  $patterns  [pattern, site, where, reserved]
     * @param  list<string>  $conflicts
     */
    private function __construct(
        private readonly array $exact,
        private readonly array $patterns,
        public readonly array $conflicts = [],
    ) {}

    /** @param iterable<Site> $sites */
    public static function build(iterable $sites, bool $strict): self
    {
        $exact = [];
        $patterns = [];
        $shapes = [];
        $conflicts = [];

        foreach ($sites as $site) {
            foreach ($site->patterns() as $pattern) {
                if ($pattern->isExact()) {
                    $key = isset($site->ports[$pattern->pattern]) ? $pattern->pattern.':'.$site->ports[$pattern->pattern] : $pattern->pattern;

                    if (isset($exact[$key]) && $exact[$key] !== $site->name) {
                        $error = AmbiguousHostPatterns::between($exact[$key], $site->name, $key);

                        if ($strict) {
                            throw $error;
                        }

                        $conflicts[] = $error->getMessage();

                        continue;
                    }

                    $exact[$key] = $site->name;

                    continue;
                }

                if (isset($shapes[$pattern->shape]) && $shapes[$pattern->shape] !== $site->name) {
                    $error = AmbiguousHostPatterns::between($shapes[$pattern->shape], $site->name, $pattern->pattern);

                    if ($strict) {
                        throw $error;
                    }

                    $conflicts[] = $error->getMessage();

                    continue;
                }

                $shapes[$pattern->shape] = $site->name;
                $patterns[] = [$pattern->pattern, $site->name, $site->where, $pattern->literalLength, $site->reserved];
            }
        }

        // Stable sort: most literal characters first, then declaration order.
        usort($patterns, fn (array $a, array $b) => $b[3] <=> $a[3]);

        return new self($exact, array_map(fn (array $p) => [$p[0], $p[1], $p[2], $p[4]], $patterns), $conflicts);
    }

    /** @return array{site: string, parameters: array<string, string>, kind: 'exact'|'pattern'}|null */
    public function match(string $host, ?int $port = null): ?array
    {
        if ($port !== null && isset($this->exact[$host.':'.$port])) {
            return ['site' => $this->exact[$host.':'.$port], 'parameters' => [], 'kind' => 'exact'];
        }

        if (isset($this->exact[$host])) {
            return ['site' => $this->exact[$host], 'parameters' => [], 'kind' => 'exact'];
        }

        foreach ($this->patterns as $entry) {
            [$pattern, $site, $where] = $entry;
            $parameters = HostPattern::parse($pattern, $where, $entry[3] ?? true)->matches($host);

            if ($parameters !== null) {
                return ['site' => $site, 'parameters' => $parameters, 'kind' => 'pattern'];
            }
        }

        return null;
    }

    /** @return list<string> regexes (without delimiters) for Request::setTrustedHosts() */
    public function regexes(): array
    {
        // Trusted hosts never carry the port.
        $hosts = array_unique(array_map(fn (string $key) => (string) preg_replace('/:\d+$/', '', $key), array_keys($this->exact)));
        $regexes = array_values(array_map(fn (string $host) => '^'.preg_quote($host, '#').'$', $hosts));

        foreach ($this->patterns as [$pattern, , $where]) {
            $regex = HostPattern::parse($pattern, $where)->regex();
            $regexes[] = substr($regex, 1, strrpos($regex, '#') - 1);
        }

        return $regexes;
    }

    /** @return array{exact: array<string, string>, patterns: list<array{0: string, 1: string, 2: array<string, string>}>, conflicts: list<string>} */
    public function toArray(): array
    {
        return ['exact' => $this->exact, 'patterns' => $this->patterns, 'conflicts' => $this->conflicts];
    }

    /** @param array{exact: array<string, string>, patterns: list<array{0: string, 1: string, 2: array<string, string>}>, conflicts?: list<string>} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['exact'], $data['patterns'], $data['conflicts'] ?? []);
    }
}
