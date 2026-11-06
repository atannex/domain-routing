<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Tests\Combo;

use Atannex\Tenancy\Tenancy;
use Atannex\Tenancy\TenancyModulesServiceProvider;
use Atannex\Tenancy\TenancyServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

abstract class TenancyTestCase extends ComboTestCase
{
    use RefreshDatabase;

    protected array $requires = [Tenancy::class];

    protected array $sites = [
        'central' => ['hosts' => ['central.test'], 'middleware' => ['web']],
        'tenant' => ['hosts' => ['dynamic'], 'middleware' => ['web']],
    ];

    protected function getPackageProviders($app): array
    {
        return [TenancyServiceProvider::class, TenancyModulesServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('tenancy.identification.central_domains', ['central.test']);
        $app['config']->set('tenancy.database.sqlite_path', sys_get_temp_dir().'/dr-tenancy-'.getmypid());
    }
}
