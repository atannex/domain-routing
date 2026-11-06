<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Hosts;

use Atannex\DomainRouting\Exceptions\AmbiguousHostPatterns;
use Atannex\DomainRouting\Sites\Site;

/** @internal Exact hosts in a hash map; parameterised patterns most-literal first. */
final class HostIndex
{
    /**
     * @param  array<string, string>  $exact  host => site
     * @param  list<array{0: string, 1: string, 2: array<string, string>}>  $patterns  [pattern, site, where]
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
                    if (isset($exact[$pattern->pattern]) && $exact[$pattern->pattern] !== $site->name) {
                        $error = AmbiguousHostPatterns::between($exact[$pattern->pattern], $site->name, $pattern->pattern);

                        if ($strict) {
                            throw $error;
                        }

                        $conflicts[] = $error->getMessage();

                        continue;
                    }

                    $exact[$pattern->pattern] = $site->name;

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
                $patterns[] = [$pattern->pattern, $site->name, $site->where, $pattern->literalLength];
            }
        }

        // Stable sort: most literal characters first, then declaration order.
        usort($patterns, fn (array $a, array $b) => $b[3] <=> $a[3]);

        return new self($exact, array_map(fn (array $p) => [$p[0], $p[1], $p[2]], $patterns), $conflicts);
    }

    /** @return array{site: string, parameters: array<string, string>, kind: 'exact'|'pattern'}|null */
    public function match(string $host): ?array
    {
        if (isset($this->exact[$host])) {
            return ['site' => $this->exact[$host], 'parameters' => [], 'kind' => 'exact'];
        }

        foreach ($this->patterns as [$pattern, $site, $where]) {
            $parameters = HostPattern::parse($pattern, $where)->matches($host);

            if ($parameters !== null) {
                return ['site' => $site, 'parameters' => $parameters, 'kind' => 'pattern'];
            }
        }

        return null;
    }

    /** @return list<string> regexes (without delimiters) for Request::setTrustedHosts() */
    public function regexes(): array
    {
        $regexes = array_map(fn (string $host) => '^'.preg_quote($host, '#').'$', array_keys($this->exact));

        foreach ($this->patterns as [$pattern, , $where]) {
            $regexes[] = substr(HostPattern::parse($pattern, $where)->regex(), 1, -1);
        }

        return array_values($regexes);
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
