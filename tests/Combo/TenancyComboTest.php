<?php

declare(strict_types=1);

use Atannex\DomainRouting\Integrations\Tenancy\TenancyHostProvider;
use Atannex\DomainRouting\Routing\SiteRoutes;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Atannex\DomainRouting\Tests\Combo\TenancyTestCase;
use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Atannex\Tenancy\Enums\DomainType;
use Atannex\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Route;

uses(TenancyTestCase::class);

beforeEach(function () {
    $this->acme = Tenant::factory()->withDomain('acme-shop.example')->create(['slug' => 'acme']);
    $this->acme->domains()->forceCreate(['host' => 'old-acme.example', 'type' => DomainType::Alias, 'verified_at' => now()]);

    Route::site('tenant', function () {
        Route::get('/', fn () => response()->json(['tenant' => tenant()?->getTenantSlug(), 'site' => current_site()?->name()]))->name('home');
        Route::get('/orders', EchoController::class)->name('orders');
    });
    Route::site('central', fn () => Route::get('/', fn () => 'central')->name('home'));
});

it('serves verified tenant domains on the tenant site, identified by tenancy', function () {
    $this->get('http://acme-shop.example/')->assertOk()->assertJson(['tenant' => 'acme', 'site' => 'tenant']);
});

it('serves {slug}.central subdomains', function () {
    $this->get('http://acme.central.test/')->assertJson(['tenant' => 'acme']);
});

it('keeps the central site apart', function () {
    $this->get('http://central.test/')->assertOk()->assertSee('central');
});

it('sends aliases to the primary domain in one hop', function () {
    $this->get('http://old-acme.example/orders?x=1')->assertStatus(301)->assertRedirect('http://acme-shop.example/orders?x=1');
});

it('answers 404 for unknown and unverified domains', function () {
    $this->acme->domains()->forceCreate(['host' => 'unverified.example', 'type' => DomainType::Alias]);

    $this->get('http://nobody.example/')->assertNotFound();
    $this->get('http://unverified.example/')->assertNotFound();
});

it('builds tenant URLs from anywhere, with tenancy\'s scheme', function () {
    expect(route('tenant.orders', ['tenant' => 'acme']))->toBe('https://acme-shop.example/orders')
        ->and(route('tenant.orders', ['tenant' => $this->acme, 'page' => 2]))->toBe('https://acme-shop.example/orders?page=2')
        ->and(site_url('tenant', '/x', ['tenant' => 'acme']))->toBe('https://acme-shop.example/x');

    $other = Tenant::factory()->create(['slug' => 'bare']);
    expect(route('tenant.orders', ['tenant' => $other]))->toBe('https://bare.central.test/orders');
});

it('links to the current tenant inside a tenant request', function () {
    Route::site('tenant', fn () => Route::get('/link', fn () => route('tenant.orders')));

    $this->get('http://acme-shop.example/link')->assertSee('http://acme-shop.example/orders');
});

it('puts tenancy middleware in front on the tenant site only', function () {
    $sites = app(SiteRegistry::class);

    expect(SiteRoutes::middleware($sites->get('tenant')))->toBe(['tenant', 'web'])
        ->and(SiteRoutes::middleware($sites->get('central')))->toBe(['central', 'web']);
});

it('keeps central routes off tenant domains, except shared paths and package endpoints', function () {
    Route::get('/admin', fn () => 'admin panel');
    Route::get('/livewire/update', fn () => 'livewire');

    $this->get('http://central.test/admin')->assertSee('admin panel');
    $this->get('http://acme-shop.example/admin')->assertNotFound();
    $this->get('http://acme.central.test/admin')->assertNotFound();
    $this->get('http://acme-shop.example/livewire/update')->assertSee('livewire');
});

it('serves the handoff endpoint on tenant domains', function () {
    $this->get('http://acme-shop.example/_handoff?t=bad')->assertForbidden();
});

it('meets the HostProvider contract', function () {
    $provider = new TenancyHostProvider;
    $sites = app(SiteRegistry::class);

    expect($provider->match('acme-shop.example', $sites)?->parameters)->toBe(['tenant' => 'acme'])
        ->and($provider->match('nobody.example', $sites))->toBeNull()
        ->and($provider->match('localhost', $sites))->toBeNull();
});
