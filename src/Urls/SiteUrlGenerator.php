<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Urls;

use Illuminate\Routing\UrlGenerator;

/** @internal Laravel's URL generator with site-aware route roots. */
final class SiteUrlGenerator extends UrlGenerator
{
    protected function routeUrl()
    {
        if (! $this->routeGenerator) {
            $this->routeGenerator = new SiteRouteUrlGenerator($this, $this->request);
        }

        return $this->routeGenerator;
    }
}
