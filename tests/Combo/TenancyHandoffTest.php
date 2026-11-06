<?php

declare(strict_types=1);

use Atannex\DomainRouting\Handoff\Handoffs;
use Atannex\DomainRouting\Handoff\SessionStamps;
use Atannex\DomainRouting\Support\CentralCache;
use Atannex\DomainRouting\Tests\Combo\TenancyTestCase;
use Atannex\DomainRouting\Tests\Fixtures\User;
use Atannex\Tenancy\Models\Tenant;
use Atannex\Tenancy\Tenancy;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(TenancyTestCase::class);

beforeEach(function () {
    config(['domain-routing.stamps.enabled' => true]);
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    $this->user = User::create(['name' => 'Ada']);
    $this->acme = Tenant::factory()->withDomain('acme-shop.example')->create(['slug' => 'acme']);

    Route::site('central', fn () => Route::get('/go', fn () => app(Handoffs::class)->to((string) request('to'))));
    Route::site('tenant', fn () => Route::get('/me', fn () => tenant()?->getTenantSlug().':'.(auth()->id() ?? 'guest')));
});

it('hands a central session to a tenant domain, with tenancy initialised for the redeem', function () {
    $this->actingAs($this->user);
    $start = $this->get($this->get('http://central.test/go?to='.urlencode('http://acme-shop.example/me'))->headers->get('Location'));
    $state = $start->getCookie(Handoffs::COOKIE)->getValue();
    $redeem = $this->get($start->headers->get('Location'))->headers->get('Location');
    app('auth')->forgetGuards();
    app(Tenancy::class)->end();

    expect($redeem)->toStartWith('http://acme-shop.example/_handoff?t=');

    $this->withCookie(Handoffs::COOKIE, $state)->get($redeem)->assertRedirect('/me');
    $this->assertAuthenticatedAs($this->user);
});

it('keeps tokens and stamps out of the per-tenant cache prefix', function () {
    $tenancy = app(Tenancy::class);
    $tenancy->initialize($this->acme);

    CentralCache::store(null)->put('domain-routing:probe', 'same everywhere', 60);
    app(SessionStamps::class)->revokeAll($this->user);
    $stamp = app(SessionStamps::class)->current('web', (string) $this->user->id);

    $tenancy->end();

    expect(CentralCache::store(null)->get('domain-routing:probe'))->toBe('same everywhere')
        ->and(app(SessionStamps::class)->current('web', (string) $this->user->id))->toBe($stamp)
        ->and($stamp)->not->toBe('0');
});
