<?php

declare(strict_types=1);

use Atannex\DomainRouting\Exceptions\AmbiguousHostPatterns;
use Atannex\DomainRouting\Hosts\HostIndex;
use Atannex\DomainRouting\Hosts\IndexCache;
use Atannex\DomainRouting\Sites\Site;
use Atannex\DomainRouting\Sites\SiteRegistry;

function sites(array $config): array
{
    return array_values(SiteRegistry::fromConfig($config, 'production')->all());
}

it('prefers exact hosts over patterns', function () {
    $index = HostIndex::build(sites([
        'app' => ['hosts' => ['{account}.atannex.com']],
        'api' => ['hosts' => ['api.atannex.com']],
    ]), true);

    expect($index->match('api.atannex.com'))->toBe(['site' => 'api', 'parameters' => [], 'kind' => 'exact'])
        ->and($index->match('acme.atannex.com'))->toBe(['site' => 'app', 'parameters' => ['account' => 'acme'], 'kind' => 'pattern'])
        ->and($index->match('nope.example'))->toBeNull();
});

it('prefers the more literal pattern', function () {
    $index = HostIndex::build(sites([
        'broad' => ['hosts' => ['{x}.example.com']],
        'shop' => ['hosts' => ['{store}.shop.example.com']],
    ]), true);

    expect($index->match('a.shop.example.com')['site'])->toBe('shop')
        ->and($index->match('shop.example.com')['site'])->toBe('broad');
});

it('refuses ambiguous definitions when strict', function (array $config) {
    HostIndex::build(sites($config), true);
})->throws(AmbiguousHostPatterns::class)->with([
    'same exact host' => [['a' => ['hosts' => ['x.test']], 'b' => ['hosts' => ['x.test']]]],
    'same shape' => [['a' => ['hosts' => ['{p}.x.test']], 'b' => ['hosts' => ['{q}.x.test']]]],
]);

it('records conflicts and keeps the first site when not strict', function () {
    $index = HostIndex::build(sites(['a' => ['hosts' => ['x.test']], 'b' => ['hosts' => ['x.test']]]), false);

    expect($index->match('x.test')['site'])->toBe('a')->and($index->conflicts)->toHaveCount(1);
});

it('round-trips through an array and a cache file', function () {
    $index = HostIndex::build(sites(['app' => ['hosts' => ['{a}.x.test', 'x.test']]]), true);
    $path = sys_get_temp_dir().'/dr-index-'.getmypid().'.php';
    $cache = new IndexCache($path);

    $cache->write($index, 'hash-1');

    expect($cache->load('hash-1')?->match('q.x.test'))->toBe($index->match('q.x.test'))
        ->and($cache->load('other-hash'))->toBeNull()
        ->and($cache->storedHash())->toBe('hash-1');

    $cache->clear();
    expect($cache->exists())->toBeFalse();
});

it('exposes trusted-host regexes', function () {
    $index = HostIndex::build(sites(['app' => ['hosts' => ['{a}.x.test', 'x.test']]]), true);

    foreach (['x.test', 'q.x.test'] as $host) {
        expect(collect($index->regexes())->contains(fn ($re) => preg_match('#'.$re.'#', $host) === 1))->toBeTrue();
    }
});

it('is built lazily by the registry and dropped on change', function () {
    $registry = SiteRegistry::fromConfig(['a' => ['hosts' => ['a.test']]], 'local');
    expect($registry->index()->match('a.test')['site'])->toBe('a');

    $registry->define('b', ['hosts' => ['b.test']], 'local');
    expect($registry->index()->match('b.test')['site'])->toBe('b');
});
