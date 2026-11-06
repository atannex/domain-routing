<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Controllers;

use Atannex\DomainRouting\Handoff\Handoffs;
use Atannex\DomainRouting\Handoff\RejectionReason;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** @internal GET /_handoff?t=… */
final class HandoffController
{
    public function __invoke(Request $request, Handoffs $handoffs): Response
    {
        $result = $handoffs->redeem($request);

        if ($result instanceof RejectionReason) {
            return new Response('This sign-in link is invalid or has expired.', 403, [
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        return $result;
    }
}
