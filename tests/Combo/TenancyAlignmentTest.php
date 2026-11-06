<?php

declare(strict_types=1);

use Atannex\DomainRouting\Hosts\HostPattern;
use Atannex\DomainRouting\Routing\SiteRoutes;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Atannex\DomainRouting\Tests\Combo\TenancyTestCase;
use Atannex\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Route;

uses(TenancyTestCase::class);

beforeEach(function () {
    $this->acme = Tenant::factory()->withDomain('acme-shop.example')->create(['slug' => 'acme']);
    Route::site('tenant', fn () => Route::get('/orders', fn () => 'orders')->name('orders'));
});

it('takes tenancy central domains through the tenancy:central token', function () {
    expect(app(SiteRegistry::class)->get('central')->hosts)->toBe(['central.test']);
});

it('builds relative tenant URLs from central code (tenant_route)', function () {
    // Both packages build the same link from outside a request (tenancy's scheme rule: https).
    expect(route('tenant.orders', [], false))->toBe('/orders')
        ->and(tenant_route($this->acme, 'tenant.orders'))->toBe('https://acme-shop.example/orders')
        ->and(route('tenant.orders', ['tenant' => $this->acme]))->toBe('https://acme-shop.example/orders');
});

it('serves tenancy impersonation and invitation links on tenant domains', function () {
    $this->get('http://acme-shop.example/tenancy/impersonate/not-a-token');
    expect(app('router')->current()?->getName())->toBe('tenancy.impersonate');

    $this->get('http://acme-shop.example/tenancy/invitations/not-a-token');
    expect(app('router')->current()?->getName())->toBe('tenancy.invitations.accept');
});

it("answers tenancy's TLS ask from localhost", function () {
    $this->get('http://localhost/tenancy/tls/ask?domain=acme-shop.example')->assertOk();
    $this->get('http://localhost/tenancy/tls/ask?domain=nobody.example')->assertNotFound();
});

it("puts tenancy's central guard on the central site when asked", function () {
    expect(SiteRoutes::middleware(app(SiteRegistry::class)->get('central')))->toBe(['central', 'web']);
});

it('never reads a tenancy reserved slug as a host parameter', function () {
    expect(HostPattern::$reserved)->toContain('www');
});

it('flags a static pattern that would swallow tenant subdomains', function () {
    $this->artisan('domains:doctor')->assertSuccessful();
});
