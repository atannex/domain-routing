<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Facades;

use Illuminate\Support\Facades\Facade;

/** @see \Atannex\DomainRouting\DomainRouting */
final class DomainRouting extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Atannex\DomainRouting\DomainRouting::class;
    }
}
