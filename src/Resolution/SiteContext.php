<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Resolution;

use Closure;

/** The site of the current request or job. Bound scoped, so Octane and queue workers start clean. */
final class SiteContext
{
    private ?SiteMatch $current = null;

    public function current(): ?SiteMatch
    {
        return $this->current;
    }

    public function set(?SiteMatch $match): void
    {
        $this->current = $match;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(?SiteMatch $match, Closure $callback): mixed
    {
        $previous = $this->current;
        $this->current = $match;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }

    public function is(string ...$names): bool
    {
        return $this->current !== null && in_array($this->current->name(), $names, true);
    }
}
