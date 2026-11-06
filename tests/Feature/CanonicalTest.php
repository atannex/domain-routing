<?php

declare(strict_types=1);

use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => Route::any('/page', EchoController::class));

it('strips www by default', function () {
    $this->get('http://www.atannex.test/page?x=1')->assertRedirect('http://atannex.test/page?x=1')->assertStatus(301);
    $this->get('http://atannex.test/page')->assertOk();
});

it('sends aliases to the explicit canonical host in one hop with https', function () {
    $this->withSites(['m' => ['hosts' => ['old.test', 'www.new.test', 'new.test'], 'canonical' => 'new.test', 'scheme' => 'https']]);
    Route::get('/page', EchoController::class);

    $this->get('http://old.test/page')->assertRedirect('https://new.test/page');
    $this->get('http://www.new.test/page')->assertRedirect('https://new.test/page');
    $this->get('https://new.test/page')->assertOk();
});

it('does not loop behind a TLS-terminating proxy', function () {
    $this->withSites(['m' => ['hosts' => ['new.test'], 'scheme' => 'https']]);
    TrustProxies::at('*');
    Route::get('/page', EchoController::class);

    $this->get('http://new.test/page', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])->assertOk();
    $this->get('http://new.test/page')->assertRedirect('https://new.test/page');
    TrustProxies::flushState();
});

it('follows the redirect map', function () {
    $this->withSites(['m' => ['hosts' => ['a.test'], 'redirects' => ['/old' => '/page', '/gone' => ['to' => 'https://elsewhere.test/', 'status' => 302]]]]);
    Route::get('/page', EchoController::class);

    $this->get('http://a.test/old')->assertRedirect('http://a.test/page')->assertStatus(301);
    $this->get('http://a.test/gone')->assertRedirect('https://elsewhere.test/')->assertStatus(302);
});

it('answers 421 to non-GET requests on the wrong host, or 308 when configured', function () {
    $this->post('http://www.atannex.test/page')->assertStatus(421);

    $this->withSettings(['domain-routing.canonical.non_get' => 308]);
    Route::any('/page', EchoController::class);
    $this->post('http://www.atannex.test/page')->assertStatus(308)->assertRedirect('http://atannex.test/page');
});

it('can turn the www rule off', function () {
    $this->withSettings(['domain-routing.canonical.www' => null]);
    Route::get('/page', EchoController::class);

    $this->get('http://www.atannex.test/page')->assertOk();
});
