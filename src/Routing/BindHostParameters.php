<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Routing;

use Atannex\DomainRouting\Resolution\SiteContext;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Illuminate\Routing\Events\RouteMatched;

/** @internal {account}.example.com → route parameter "account", first, like Laravel's domain parameters. */
final class BindHostParameters
{
    public function __construct(private readonly SiteContext $context) {}

    public function handle(RouteMatched $event): void
    {
        $match = $this->context->current();

        if ($match === null || $match->source !== SiteMatch::PATTERN || $match->parameters === []) {
            return;
        }

        if (SiteValidator::siteOf($event->route->action) !== $match->name()) {
            return;
        }

        $event->route->parameters = $match->parameters + ($event->route->parameters ?? []);
    }
}
