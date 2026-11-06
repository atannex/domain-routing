<?php

declare(strict_types=1);

use Atannex\DomainRouting\Hosts\IndexCache;
use Illuminate\Support\Facades\Route;

it('lists sites', function () {
    $this->artisan('domains:list')->expectsOutputToContain('{account}.app.test')->assertSuccessful();
});

it('explains a host', function () {
    $this->artisan('domains:resolve', ['host' => 'Acme.App.Test'])
        ->expectsOutputToContain('host pattern')
        ->expectsOutputToContain('"account":"acme"')
        ->assertSuccessful();

    $this->artisan('domains:resolve', ['host' => 'nope.example'])->assertFailed();
});

it('caches and clears the host index, and the doctor spots a stale cache', function () {
    $cache = app(IndexCache::class);

    $this->artisan('domains:cache')->assertSuccessful();
    expect($cache->exists())->toBeTrue();

    $this->artisan('domains:doctor')->assertSuccessful();

    $this->withSites(['other' => ['hosts' => ['other.test']]]);
    $this->artisan('domains:doctor')->expectsOutputToContain('stale')->assertFailed();

    $this->artisan('domains:clear')->assertSuccessful();
    expect(app(IndexCache::class)->exists())->toBeFalse();
});

it('reports route names used on more than one site', function () {
    $this->withSettings(['domain-routing.route_names' => 'none']);
    Route::site('marketing', fn () => Route::get('/', fn () => 'm')->name('home'));
    Route::site('api', fn () => Route::get('/', fn () => 'a')->name('home'));

    $this->artisan('domains:doctor')->expectsOutputToContain('Route name [home]')->assertFailed();
});

it('reports ambiguous hosts and a bad default', function () {
    $this->withSites(['a' => ['hosts' => ['{x}.t.test']], 'b' => ['hosts' => ['{y}.t.test']]], ['app.env' => 'production']);
    config(['domain-routing.unmatched' => 'default', 'domain-routing.default' => 'nope']);

    $this->artisan('domains:doctor')
        ->expectsOutputToContain('both claim')
        ->expectsOutputToContain('default names no site')
        ->assertFailed();
});
