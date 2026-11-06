<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Queue;

use Atannex\DomainRouting\Resolution\SiteContext;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Illuminate\Contracts\Queue\Job;

/** @internal Jobs carry the site they were dispatched from, so URLs in mail and notifications point at it. */
final class QueueContext
{
    public const string KEY = 'domainRouting';

    /** @var list<?SiteMatch> */
    private array $stack = [];

    public function __construct(private readonly SiteContext $context, private readonly SiteRegistry $sites) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $match = $this->context->current();

        return $match === null ? [] : [self::KEY => [
            'site' => $match->name(),
            'host' => $match->host,
            'parameters' => $match->parameters,
            'source' => $match->source,
            'canonical' => $match->canonicalHost,
        ]];
    }

    public function enter(Job $job): void
    {
        $this->stack[] = $this->context->current();
        $data = $job->payload()[self::KEY] ?? null;

        if (is_array($data) && $this->sites->has((string) $data['site'])) {
            $this->context->set(new SiteMatch(
                $this->sites->get((string) $data['site']),
                (string) $data['host'],
                (array) $data['parameters'],
                (string) $data['source'],
                $data['canonical'] ?? null,
            ));
        }
    }

    public function leave(): void
    {
        if ($this->stack !== []) {
            $this->context->set(array_pop($this->stack));
        }
    }
}
