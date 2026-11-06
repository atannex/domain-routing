<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Tests\Fixtures\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EchoController
{
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse([
            'site' => current_site()?->name(),
            'route' => $request->route()?->getName(),
            'parameters' => $request->route()?->parameters() ?? [],
            'controller' => 'echo',
        ]);
    }

    public function other(Request $request): JsonResponse
    {
        return new JsonResponse(['site' => current_site()?->name(), 'controller' => 'other']);
    }
}
