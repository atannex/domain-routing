<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * throttle:60,1 keys guests by route domain + IP; site routes have no route
 * domain, so every site (and tenant) would share one bucket. Key by host too.
 */
class SiteThrottleRequests extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        return SiteThrottle::scope($request).parent::resolveRequestSignature($request);
    }
}
