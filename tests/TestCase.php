<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Tests;

use Atannex\DomainRouting\DomainRoutingServiceProvider;
use Atannex\DomainRouting\Facades\DomainRouting;
use Atannex\DomainRouting\Testing\InteractsWithSites;
use Illuminate\Contracts\Config\Repository;
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

    /**
     * In-memory SQLite, or a real server when DOMAIN_ROUTING_TEST_DB=mysql|mariadb|pgsql
     * (DOMAIN_ROUTING_TEST_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD, defaults for CI services).
     *
     * @return array<string, mixed>
     */
    public static function databaseConnection(): array
    {
        $driver = getenv('DOMAIN_ROUTING_TEST_DB') ?: 'sqlite';

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
        }

        $env = fn (string $key, string $default): string => getenv('DOMAIN_ROUTING_TEST_DB_'.$key) ?: $default;
        $pgsql = $driver === 'pgsql';

        return [
            'driver' => $pgsql ? 'pgsql' : ($driver === 'mariadb' ? 'mariadb' : 'mysql'),
            'host' => $env('HOST', '127.0.0.1'),
            'port' => $env('PORT', $pgsql ? '5432' : '3306'),
            'database' => $env('DATABASE', 'domain_routing_test'),
            'username' => $env('USERNAME', $pgsql ? 'postgres' : 'root'),
            'password' => $env('PASSWORD', ''),
            'charset' => $pgsql ? 'utf8' : 'utf8mb4',
            'collation' => $pgsql ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    /** The array store, or Redis when DOMAIN_ROUTING_TEST_CACHE=redis (a fresh key prefix per run). */
    private static function cacheStore(Repository $config): void
    {
        if (getenv('DOMAIN_ROUTING_TEST_CACHE') !== 'redis') {
            $config->set('cache.default', 'array');

            return;
        }

        // A fresh prefix per test: Redis keeps keys (rate limits, tokens) between tests otherwise.
        $prefix = 'drtest_'.bin2hex(random_bytes(4)).'_';

        $config->set('database.redis.client', extension_loaded('redis') ? 'phpredis' : 'predis');
        $config->set('database.redis.default', ['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => getenv('REDIS_PORT') ?: '6379', 'database' => '15']);
        $config->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'default', 'lock_connection' => 'default']);
        $config->set('cache.default', 'redis');
        $config->set('cache.prefix', $prefix);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::databaseConnection());
        self::cacheStore($app['config']);
        $app['config']->set('domain-routing.sites', $this->sites);
        $app['config']->set('auth.providers.users.model', Fixtures\User::class);

        foreach ($this->settings as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}
