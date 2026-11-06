<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Urls;

use Atannex\DomainRouting\Hosts\HostBuilder;
use Atannex\DomainRouting\Resolution\SiteMatch;
use Illuminate\Support\Facades\URL;

/** @internal Roots url(), asset() and unsited routes at a site while outside its request (jobs, run()). */
final class SiteRoot
{
    public static function current(): ?string
    {
        $url = app('url');

        return $url instanceof SiteUrlGenerator ? $url->forcedRoot() : null;
    }

    public static function force(SiteMatch $match): void
    {
        URL::forceRootUrl(app(HostBuilder::class)->rootFor($match->site, $match->canonicalHost ?? $match->host));
    }
}
