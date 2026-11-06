<?php

declare(strict_types=1);

use Atannex\DomainRouting\Events\SiteNotResolved;
use Atannex\DomainRouting\Events\SiteResolved;
use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/whoami', EchoController::class);
    Route::get('/up', fn () => 'up');
});

it('resolves the site from the host', function () {
    $this->get('http://ACME.app.test/whoami')->assertOk()->assertJson(['site' => 'app']);
    $this->get('http://atannex.test/whoami')->assertJson(['site' => 'marketing']);
});

it('answers 404 for unknown hosts and dispatches SiteNotResolved', function () {
    Event::fake([SiteNotResolved::class]);

    $this->get('http://evil.example/whoami')->assertNotFound();

    Event::assertDispatched(SiteNotResolved::class, fn ($e) => $e->host === 'evil.example');
});

it('lets health checks through on unknown hosts', function () {
    $this->get('http://10.0.0.7/up')->assertOk()->assertSee('up');
});

it('dispatches SiteResolved', function () {
    Event::fake([SiteResolved::class]);
    $this->get('http://api.atannex.test/whoami');
    Event::assertDispatched(SiteResolved::class, fn ($e) => $e->match->name() === 'api');
});

it('falls back to the default site and pins links to its host', function () {
    $this->withSettings(['domain-routing.unmatched' => 'default', 'domain-routing.default' => 'marketing']);
    Route::get('/link', fn () => url('/about'));

    $this->get('http://forged.example/link')->assertOk()->assertSee('http://atannex.test/about');
});

it('redirects unknown hosts when told to', function () {
    $this->withSettings(['domain-routing.unmatched' => 'redirect:https://atannex.com']);

    $this->get('http://forged.example/whoami')->assertRedirect('https://atannex.com');
});

it('passes everything through when no sites are defined', function () {
    $this->withSites([]);
    Route::get('/whoami', EchoController::class);

    $this->get('http://anything.example/whoami')->assertOk()->assertJson(['site' => null]);
});

it('applies the site session cookie settings', function () {
    $this->withSites(['app' => ['hosts' => ['app.test'], 'session' => ['cookie' => 'atx_app']]]);
    Route::get('/cookie', fn () => config('session.cookie'));

    $this->get('http://app.test/cookie')->assertSee('atx_app');
});

it('adds the debug header only when asked', function () {
    $this->get('http://api.atannex.test/whoami')->assertHeaderMissing('X-Site');

    $this->withSettings(['domain-routing.debug_header' => true]);
    Route::get('/whoami', EchoController::class);
    $this->get('http://api.atannex.test/whoami')->assertHeader('X-Site', 'api');
});
