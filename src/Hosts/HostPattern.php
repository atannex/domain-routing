<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Hosts;

use Atannex\DomainRouting\Exceptions\InvalidHostParameter;
use Atannex\DomainRouting\Exceptions\InvalidHostPattern;
use Atannex\DomainRouting\Exceptions\MissingHostParameter;
use Illuminate\Contracts\Routing\UrlRoutable;

/** @internal "atannex.com" or "{account}.atannex.com", compiled once per process. */
final class HostPattern
{
    public const string DEFAULT_PARAMETER = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';

    /** @var array<string, self> */
    private static array $compiled = [];

    /**
     * @param  list<string>  $parameters
     * @param  array<string, string>  $constraints
     */
    private function __construct(
        public readonly string $pattern,
        public readonly array $parameters,
        public readonly int $literalLength,
        public readonly string $shape,
        private readonly string $regex,
        private readonly array $constraints,
    ) {}

    /** @param array<string, string> $where */
    public static function parse(string $raw, array $where = []): self
    {
        $key = $raw."\0".serialize($where);

        return self::$compiled[$key] ??= self::compile($raw, $where);
    }

    /** @param array<string, string> $where */
    private static function compile(string $raw, array $where): self
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw InvalidHostPattern::for($raw, 'empty');
        }

        $parts = preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*\})/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $parameters = [];
        $constraints = [];
        $regex = '';
        $pattern = '';
        $shape = '';
        $literal = 0;
        $previousWasParameter = false;

        foreach ($parts as $part) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $part, $m)) {
                if ($previousWasParameter) {
                    throw InvalidHostPattern::for($raw, 'two parameters must be separated by literal text');
                }

                $name = $m[1];

                if (in_array($name, $parameters, true)) {
                    throw InvalidHostPattern::for($raw, "parameter [{$name}] appears twice");
                }

                $constraints[$name] = $where[$name] ?? self::DEFAULT_PARAMETER;

                if (@preg_match('#^(?:'.$constraints[$name].')$#', '') === false) {
                    throw InvalidHostPattern::for($raw, "the constraint for [{$name}] is not a valid regular expression");
                }
                $parameters[] = $name;
                $regex .= '(?P<'.$name.'>'.$constraints[$name].')';
                $pattern .= '{'.$name.'}';
                $shape .= '{}';
                $previousWasParameter = true;

                continue;
            }

            if (str_contains($part, '{') || str_contains($part, '}')) {
                throw InvalidHostPattern::for($raw, 'unbalanced braces');
            }

            if (preg_match('/[^\x20-\x7e]/', $part)) {
                if (count($parts) > 1) {
                    throw InvalidHostPattern::for($raw, 'write internationalised labels in punycode (xn--…) when the pattern has parameters');
                }

                $part = HostNormalizer::normalize($part);
            }

            $part = strtolower($part);

            if (! preg_match('/^[a-z0-9.-]+$/', $part)) {
                throw InvalidHostPattern::for($raw, 'only a-z, 0-9, dots and hyphens are allowed');
            }

            $regex .= preg_quote($part, '#');
            $pattern .= $part;
            $shape .= $part;
            $literal += strlen($part);
            $previousWasParameter = false;
        }

        if ($parameters === [] && ! HostNormalizer::isValid($pattern)) {
            throw InvalidHostPattern::for($raw, 'not a valid host name');
        }

        return new self($pattern, $parameters, $literal, $shape, '#^'.$regex.'$#', $constraints);
    }

    public function isExact(): bool
    {
        return $this->parameters === [];
    }

    public function regex(): string
    {
        return $this->regex;
    }

    /** @return array<string, string>|null parameter values, or null when the host does not match */
    public function matches(string $host): ?array
    {
        if ($this->isExact()) {
            return $host === $this->pattern ? [] : null;
        }

        if (! preg_match($this->regex, $host, $m)) {
            return null;
        }

        return array_intersect_key($m, array_flip($this->parameters));
    }

    /** @param array<string, mixed> $values */
    public function build(array $values, string $site = ''): string
    {
        $host = $this->pattern;

        foreach ($this->parameters as $name) {
            if (! array_key_exists($name, $values) || $values[$name] === null || $values[$name] === '') {
                throw MissingHostParameter::for($site, $name);
            }

            $value = $values[$name] instanceof UrlRoutable ? $values[$name]->getRouteKey() : $values[$name];
            $value = strtolower((string) $value);

            if (! preg_match('#^(?:'.$this->constraints[$name].')$#', $value)) {
                throw InvalidHostParameter::for($site, $name, $value);
            }

            $host = str_replace('{'.$name.'}', $value, $host);
        }

        return $host;
    }
}
