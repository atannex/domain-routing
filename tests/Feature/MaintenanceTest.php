<?php

declare(strict_types=1);

use Atannex\DomainRouting\Edge\MaintenanceStore;
use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Illuminate\Support\Facades\Route;

beforeEach(fn () => Route::get('/page', EchoController::class));
afterEach(fn () => app(MaintenanceStore::class)->up('api'));

it('takes one site down and leaves the others up', function () {
    $this->artisan('domains:down', ['site' => 'api', '--retry' => 60])->assertSuccessful();

    $this->get('http://api.atannex.test/page')->assertStatus(503)->assertHeader('Retry-After', '60');
    $this->get('http://atannex.test/page')->assertOk();

    $this->artisan('domains:up', ['site' => 'api'])->assertSuccessful();
    $this->get('http://api.atannex.test/page')->assertOk();
});

it('lets holders of the secret through', function () {
    $this->artisan('domains:down', ['site' => 'api', '--secret' => 'let-me-in']);

    $cookie = $this->get('http://api.atannex.test/let-me-in')->assertRedirect('/')->getCookie(MaintenanceStore::cookie('api'), false);

    $this->withUnencryptedCookie(MaintenanceStore::cookie('api'), $cookie->getValue())
        ->get('http://api.atannex.test/page')->assertOk();
    $this->withUnencryptedCookie(MaintenanceStore::cookie('api'), 'forged')
        ->get('http://api.atannex.test/page')->assertStatus(503);
});

it('serves robots.txt per site', function () {
    $this->withSites(['m' => ['hosts' => ['a.test'], 'robots' => "User-agent: *\nAllow: /\n"], 'n' => ['hosts' => ['b.test']]], ['domain-routing.robots.enabled' => true]);

    $this->get('http://a.test/robots.txt')->assertOk()->assertSee('Allow: /');
    $this->get('http://b.test/robots.txt')->assertSee('Disallow: /');
});
