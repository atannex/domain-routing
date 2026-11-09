<?php

declare(strict_types=1);

use Atannex\DomainRouting\Exceptions\AmbiguousHostPatterns;
use Atannex\DomainRouting\Exceptions\InvalidHostPattern;
use Atannex\DomainRouting\Facades\DomainRouting;
use Atannex\DomainRouting\Hosts\HostIndex;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Illuminate\Support\Facades\Route;

/*
 * Sites sharing one address on different ports ("127.0.0.1:8000", "127.0.0.1:8001"):
 * one port per site in development, where an IP address has no subdomains.
 */

beforeEach(function () {
    $this->withSites([
        'marketing' => ['hosts' => ['127.0.0.1:8000'], 'middleware' => ['web']],
        'news' => ['hosts' => ['127.0.0.1:8001'], 'middleware' => ['web']],
        'app' => ['hosts' => ['{account}.app.test'], 'middleware' => ['web']],
    ]);

    Route::site('marketing', fn () => Route::get('/where', fn () => response()->json([
        'site' => current_site()?->name(),
        'news' => route('news.front'),
        'self' => route('marketing.where'),
    ]))->name('where'));
    Route::site('news', fn () => Route::get('/front', fn () => response()->json([
        'site' => current_site()?->name(),
        'marketing' => route('marketing.where'),
    ]))->name('front'));
});

it('serves each site on its own port of one address', function () {
    $this->get('http://127.0.0.1:8000/where')->assertJson(['site' => 'marketing']);
    $this->get('http://127.0.0.1:8001/front')->assertJson(['site' => 'news']);
});

it('serves nothing on a port no site listens on', function () {
    $this->get('http://127.0.0.1:8009/front')->assertNotFound();
});

it('builds links to the other site on its port', function () {
    $this->get('http://127.0.0.1:8000/where')->assertExactJson([
        'site' => 'marketing',
        'news' => 'http://127.0.0.1:8001/front',
        'self' => 'http://127.0.0.1:8000/where',
    ]);
    $this->get('http://127.0.0.1:8001/front')->assertJson(['marketing' => 'http://127.0.0.1:8000/where']);
});

it('builds port links outside requests', function () {
    expect(site_url('news', 'front'))->toBe('http://127.0.0.1:8001/front')
        ->and(route('news.front'))->toBe('http://127.0.0.1:8001/front');
});

it('resolves host and port, or host:port', function () {
    expect(DomainRouting::resolve('127.0.0.1', 8001)?->name())->toBe('news')
        ->and(DomainRouting::resolve('127.0.0.1:8000')?->name())->toBe('marketing')
        ->and(DomainRouting::resolve('127.0.0.1'))->toBeNull();
});

it('keeps the port out of trusted host patterns', function () {
    $index = HostIndex::build(SiteRegistry::fromConfig([
        'a' => ['hosts' => ['127.0.0.1:8000']],
        'b' => ['hosts' => ['127.0.0.1:8001']],
    ], 'production')->all(), true);

    expect($index->regexes())->toBe(['^127\.0\.0\.1$']);
});

it('prefers the site on the port over one on the bare host', function () {
    $index = HostIndex::build(SiteRegistry::fromConfig([
        'any' => ['hosts' => ['127.0.0.1']],
        'one' => ['hosts' => ['127.0.0.1:8001']],
    ], 'production')->all(), true);

    expect($index->match('127.0.0.1', 8001)['site'])->toBe('one')
        ->and($index->match('127.0.0.1', 8002)['site'])->toBe('any');
});

it('refuses two sites on the same host and port', function () {
    HostIndex::build(SiteRegistry::fromConfig([
        'a' => ['hosts' => ['127.0.0.1:8001']],
        'b' => ['hosts' => ['127.0.0.1:8001']],
    ], 'production')->all(), true);
})->throws(AmbiguousHostPatterns::class);

it('refuses a port on a host pattern', function () {
    SiteRegistry::fromConfig(['a' => ['hosts' => ['{x}.test:8001']]], 'production');
})->throws(InvalidHostPattern::class);

it('takes the site port from its hosts unless port is set', function () {
    $sites = SiteRegistry::fromConfig([
        'a' => ['hosts' => ['127.0.0.1:8001']],
        'b' => ['hosts' => ['127.0.0.1:8002'], 'port' => 9000],
        'c' => ['hosts' => ['example.test']],
    ], 'production');

    expect($sites->get('a')->port)->toBe(8001)
        ->and($sites->get('a')->hosts)->toBe(['127.0.0.1'])
        ->and($sites->get('b')->port)->toBe(9000)
        ->and($sites->get('c')->port)->toBeNull()
        ->and($sites->get('c')->ports)->toBe([]);
});
