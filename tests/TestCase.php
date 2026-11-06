<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Tests;

use Atannex\DomainRouting\DomainRoutingServiceProvider;
use Atannex\DomainRouting\Facades\DomainRouting;
use Atannex\DomainRouting\Testing\InteractsWithSites;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use InteractsWithSites;

    /** @var array<string, array<string, mixed>> */
    protected array $sites = [
        'marketing' => ['hosts' => ['atannex.test', 'www.atannex.test'], 'middleware' => ['web']],
        'app' => ['hosts' => ['{account}.app.test'], 'middleware' => ['web']],
        'api' => ['hosts' => ['api.atannex.test'], 'middleware' => ['api']],
    ];

    /** @var array<string, mixed> extra config applied before boot */
    protected array $settings = [];

    /** Re-boot the app with these sites and config (sites are frozen once the app boots). */
    protected function withSites(array $sites, array $settings = []): static
    {
        $this->sites = $sites;
        $this->settings = $settings;
        $this->refreshApplication();

        return $this;
    }

    protected function withSettings(array $settings): static
    {
        return $this->withSites($this->sites, $settings);
    }

    protected function getPackageProviders($app): array
    {
        return [DomainRoutingServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['DomainRouting' => DomainRouting::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('domain-routing.sites', $this->sites);
        $app['config']->set('auth.providers.users.model', Fixtures\User::class);

        foreach ($this->settings as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}
