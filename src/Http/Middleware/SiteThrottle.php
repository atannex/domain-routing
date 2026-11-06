<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Middleware;

use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Routing\SiteValidator;
use Illuminate\Http\Request;

/** @internal */
final class SiteThrottle
{
    public static function scope(Request $request): string
    {
        $site = $request->attributes->get(SiteValidator::ATTRIBUTE);

        return $site === null ? '' : sha1($site.'|'.HostNormalizer::normalize($request->getHost())).'|';
    }
}
