<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Tests\Combo;

use Atannex\Routing\RoutingServiceProvider;

abstract class RoutingTestCase extends ComboTestCase
{
    protected array $requires = [RoutingServiceProvider::class];

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), RoutingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('routing.directories', [__DIR__.'/../Fixtures/Attributed' => ['middleware' => ['web']]]);
        $app['config']->set('routing.cache', false);
    }
}
