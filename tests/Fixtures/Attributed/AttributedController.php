<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Tests\Fixtures\Attributed;

use Atannex\DomainRouting\Attributes\Site;
use Atannex\Routing\Attributes\Get;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Site('api')]
final class AttributedController
{
    #[Get('/attributed', name: 'attributed')]
    public function __invoke(Request $request): JsonResponse
    {
        return new JsonResponse(['site' => current_site()?->name(), 'route' => $request->route()?->getName()]);
    }
}
