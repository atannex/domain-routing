<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Http\Controllers;

use Atannex\DomainRouting\Handoff\Handoffs;
use Atannex\DomainRouting\Handoff\RejectionReason;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** @internal GET /_handoff/start, /_handoff/issue, /_handoff */
final class HandoffController
{
    // Laravel keeps controller instances on the route, so services are resolved per call.
    public function start(Request $request, Handoffs $handoffs): Response
    {
        return $this->respond($handoffs->start($request));
    }

    public function issue(Request $request, Handoffs $handoffs): Response
    {
        return $this->respond($handoffs->issue($request));
    }

    public function redeem(Request $request, Handoffs $handoffs): Response
    {
        return $this->respond($handoffs->redeem($request));
    }

    private function respond(Response|RejectionReason $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        return new Response('This sign-in link is invalid or has expired.', 403, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
