<?php

declare(strict_types=1);

use Atannex\DomainRouting\Events\SiteNotResolved;
use Atannex\DomainRouting\Events\SiteResolved;
use Atannex\DomainRouting\Exceptions\InvalidHostParameter;
use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Route::get('/whoami', EchoController::class);
    Route::get('/up', fn () => 'up');
});

it('resolves the site from the host', function () {
    Route::site('app', fn () => Route::get('/whoami', EchoController::class));

    $this->get('http://acme.app.test/whoami')->assertOk()->assertJson(['site' => 'app']);
    $this->get('http://atannex.test/whoami')->assertJson(['site' => 'marketing']);
});

it('sends mixed-case and trailing-dot hosts to the normal host', function () {
    $this->get('http://ATANNEX.test/whoami')->assertRedirect('http://atannex.test/whoami');
    $this->get('http://atannex.test./whoami')->assertRedirect('http://atannex.test/whoami');
});

it('keeps unsited routes off pattern hosts by default', function () {
    $this->get('http://acme.app.test/whoami')->assertNotFound();

    $this->withSettings(['domain-routing.unsited_routes' => 'static']);
    Route::get('/whoami', EchoController::class);
    $this->get('http://acme.app.test/whoami')->assertOk();
});

it('lets a site opt in to unsited routes', function () {
    $this->withSites(['app' => ['hosts' => ['{account}.app.test'], 'unsited_routes' => true]]);
    Route::get('/whoami', EchoController::class);

    $this->get('http://acme.app.test/whoami')->assertOk();
});

it('never treats reserved labels as a parameter', function () {
    $this->withSites(['app' => ['hosts' => ['{account}.atannex.test']]]);
    Route::site('app', fn () => Route::get('/whoami', EchoController::class)->name('home'));

    $this->get('http://www.atannex.test/whoami')->assertNotFound();
    $this->get('http://acme.atannex.test/whoami')->assertOk();
    expect(fn () => route('app.home', ['account' => 'www']))->toThrow(InvalidHostParameter::class);
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

it('keeps session cookies on the site host even with a shared SESSION_DOMAIN', function () {
    config(['session.domain' => '.atannex.test']);
    Route::get('/domain', fn () => var_export(config('session.domain'), true));

    $this->get('http://atannex.test/domain')->assertSee('NULL');
    expect(config('session.domain'))->toBe('.atannex.test'); // put back after the request
});

it('shares the session domain when a site asks for it', function () {
    $this->withSites(['m' => ['hosts' => ['atannex.test'], 'session' => ['domain' => '.atannex.test']]]);
    Route::get('/domain', fn () => config('session.domain'));

    $this->get('http://atannex.test/domain')->assertSee('.atannex.test');
});

it('forgets the site between requests', function () {
    Route::get('/up', fn () => var_export(current_site()?->name(), true));

    $this->get('http://api.atannex.test/whoami')->assertJson(['site' => 'api']);
    $this->get('http://api.atannex.test/up')->assertSee('NULL');
    expect(current_site())->toBeNull();
});

it('puts back the root URL the app forced', function () {
    $this->withSettings(['domain-routing.unmatched' => 'default', 'domain-routing.default' => 'marketing']);
    URL::forceRootUrl('https://app-root.test');
    Route::get('/whoami', EchoController::class);

    $this->get('http://forged.example/whoami')->assertOk();
    expect(url('/x'))->toEndWith('://app-root.test/x');
});

it('adds the debug header only when asked', function () {
    $this->get('http://api.atannex.test/whoami')->assertHeaderMissing('X-Site');

    $this->withSettings(['domain-routing.debug_header' => true]);
    Route::get('/whoami', EchoController::class);
    $this->get('http://api.atannex.test/whoami')->assertHeader('X-Site', 'api');
});
