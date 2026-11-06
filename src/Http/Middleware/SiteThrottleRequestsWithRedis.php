<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;

/** The Redis flavour of SiteThrottleRequests. */
class SiteThrottleRequestsWithRedis extends ThrottleRequestsWithRedis
{
    protected function resolveRequestSignature($request)
    {
        return SiteThrottle::scope($request).parent::resolveRequestSignature($request);
    }
}
