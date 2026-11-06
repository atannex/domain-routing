<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Controllers;

use Atannex\DomainRouting\Resolution\SiteContext;
use Symfony\Component\HttpFoundation\Response;

/** @internal robots.txt per site; anything but production defaults to Disallow: / */
final class RobotsController
{
    public function __invoke(SiteContext $context): Response
    {
        $body = $context->current()?->site->robots
            ?? (app()->environment('production') ? "User-agent: *\nDisallow:\n" : "User-agent: *\nDisallow: /\n");

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
