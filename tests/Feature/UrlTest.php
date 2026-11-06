<?php

declare(strict_types=1);

use Atannex\DomainRouting\Exceptions\InvalidHostParameter;
use Atannex\DomainRouting\Exceptions\MissingHostParameter;
use Atannex\DomainRouting\Facades\DomainRouting;
use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Route::site('marketing', fn () => Route::get('/pricing', EchoController::class)->name('pricing'));
    Route::site('app', function () {
        Route::get('/dashboard', EchoController::class)->name('dashboard');
        Route::get('/projects/{project}', EchoController::class)->name('project');
    });
    Route::get('/links', fn () => response()->json([
        'pricing' => route('marketing.pricing'),
        'dashboard' => route('app.dashboard', ['account' => 'other']),
        'mine' => route('app.dashboard'),
        'relative' => route('app.dashboard', ['account' => 'x'], false),
    ]));
});

it('roots each route at its site host', function () {
    $this->get('http://acme.app.test/links')->assertExactJson([
        'pricing' => 'http://atannex.test/pricing',
        'dashboard' => 'http://other.app.test/dashboard',
        'mine' => 'http://acme.app.test/dashboard',
        'relative' => '/dashboard',
    ]);
});

it('builds URLs outside requests', function () {
    expect(route('app.project', ['account' => 'acme', 'project' => 7]))->toBe('http://acme.app.test/projects/7')
        ->and(site_route('app', 'dashboard', ['account' => 'acme']))->toBe('http://acme.app.test/dashboard')
        ->and(site_url('marketing', 'about'))->toBe('http://atannex.test/about')
        ->and(site_asset('marketing', 'logo.svg'))->toBe('http://atannex.test/logo.svg');
});

it('uses the configured scheme and asset host', function () {
    $this->withSites(['cdn' => ['hosts' => ['atannex.test'], 'scheme' => 'https', 'asset_url' => 'https://cdn.atannex.test/']]);

    expect(site_url('cdn', '/x'))->toBe('https://atannex.test/x')
        ->and(site_asset('cdn', 'a.css'))->toBe('https://cdn.atannex.test/a.css');
});

it('refuses to guess a missing host parameter', function () {
    route('app.dashboard');
})->throws(MissingHostParameter::class, 'account');

it('never builds a foreign host from a parameter', function (string $value) {
    route('app.dashboard', ['account' => $value]);
})->throws(InvalidHostParameter::class)->with(['evil.com/x', 'a.b', 'x@evil.com', 'UPPER_case', ' ']);

it('treats an empty host parameter as missing', function () {
    route('app.dashboard', ['account' => '']);
})->throws(MissingHostParameter::class);

it('signs URLs across sites and checks the host', function () {
    Route::site('app', fn () => Route::get('/invite', fn () => request()->hasValidSignature() ? 'valid' : 'invalid')->name('invite'));

    $url = URL::signedRoute('app.invite', ['account' => 'acme']);
    expect($url)->toStartWith('http://acme.app.test/invite?signature=');

    $this->get($url)->assertSee('valid');
    $this->get(str_replace('acme.app.test', 'other.app.test', $url))->assertSee('invalid');
});

it('runs code as if inside a site', function () {
    $url = DomainRouting::run('app', fn () => route('app.dashboard'), ['account' => 'acme']);

    expect($url)->toBe('http://acme.app.test/dashboard');
});
