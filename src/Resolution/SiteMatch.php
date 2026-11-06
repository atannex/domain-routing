<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Resolution;

use Atannex\DomainRouting\Sites\Site;

/** Which site a host belongs to, and why. */
final readonly class SiteMatch
{
    public const string EXACT = 'exact';

    public const string PATTERN = 'pattern';

    public const string PROVIDER = 'provider';

    public const string DEFAULT = 'default';

    /** @param array<string, string> $parameters */
    public function __construct(
        public Site $site,
        public string $host,
        public array $parameters = [],
        public string $source = self::EXACT,
        public ?string $canonicalHost = null,
    ) {}

    public function name(): string
    {
        return $this->site->name;
    }
}
