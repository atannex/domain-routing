<?php

declare(strict_types=1);

use Atannex\DomainRouting\Events\HandoffRejected;
use Atannex\DomainRouting\Exceptions\InvalidHandoffTarget;
use Atannex\DomainRouting\Handoff\Handoffs;
use Atannex\DomainRouting\Handoff\RejectionReason;
use Atannex\DomainRouting\Handoff\SessionStamps;
use Atannex\DomainRouting\Tests\Fixtures\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->withSettings(['domain-routing.handoff.enabled' => true]);
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    $this->user = User::create(['name' => 'Ada']);
    Route::middleware('web')->get('/me', fn () => auth()->id() ?? 'guest');
});

function tokenFrom(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return $q['t'];
}

it('signs the user in on the other domain once', function () {
    $url = app(Handoffs::class)->url($this->user, 'http://acme.app.test/me?tab=1');

    expect($url)->toStartWith('http://acme.app.test/_handoff?t=');

    $this->get($url)->assertRedirect('/me?tab=1')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->assertAuthenticatedAs($this->user);

    auth()->logout();
    Event::fake([HandoffRejected::class]);
    $this->get($url)->assertForbidden();
    Event::assertDispatched(HandoffRejected::class, fn ($e) => $e->reason === RejectionReason::ExpiredOrUsed);
    $this->assertGuest();
});

it('refuses targets no site serves', function () {
    app(Handoffs::class)->url($this->user, 'https://evil.example/');
})->throws(InvalidHandoffTarget::class);

it('refuses non-http targets', function () {
    app(Handoffs::class)->url($this->user, 'javascript://acme.app.test/');
})->throws(InvalidHandoffTarget::class);

it('binds the token to its host', function () {
    $url = app(Handoffs::class)->url($this->user, 'http://acme.app.test/me');
    Event::fake([HandoffRejected::class]);

    $this->get('http://other.app.test/_handoff?t='.tokenFrom($url))->assertForbidden();
    Event::assertDispatched(HandoffRejected::class, fn ($e) => $e->reason === RejectionReason::HostMismatch);
    $this->assertGuest();
});

it('expires tokens', function () {
    $url = app(Handoffs::class)->url($this->user, 'http://acme.app.test/me');
    $this->travel(61)->seconds();

    $this->get($url)->assertForbidden();
    $this->assertGuest();
});

it('rejects malformed tokens without touching the store', function (string $token) {
    $this->get('http://acme.app.test/_handoff?t='.urlencode($token))->assertForbidden();
})->with(['', 'short', str_repeat('a', 44), str_repeat('*', 43)]);

it('binds to the IP when asked', function () {
    config(['domain-routing.handoff.bind_ip' => true]);
    $url = app(Handoffs::class)->url($this->user, 'http://acme.app.test/me');

    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->get($url)->assertForbidden();
});

it('binds the token to the browser that asked for it', function () {
    $url = $this->withHeader('User-Agent', 'Browser A')->get('http://atannex.test/me') && true
        ? app(Handoffs::class)->url($this->user, 'http://acme.app.test/me') : '';

    $this->withHeader('User-Agent', 'Browser B')->get($url)->assertForbidden();
    $this->assertGuest();
});

it('rejects users that no longer exist', function () {
    $url = app(Handoffs::class)->url($this->user, 'http://acme.app.test/me');
    $this->user->delete();

    $this->get($url)->assertForbidden();
});

it('only redirects to same-host paths', function (string $path, string $expected) {
    expect(Handoffs::safePath($path))->toBe($expected);
})->with([
    ['/dashboard?x=1', '/dashboard?x=1'],
    ['//evil.com', '/'],
    ['/\\evil.com', '/'],
    ['https://evil.com', '/'],
]);

it('signs out every session when the stamp is revoked', function () {
    $this->withSettings(['domain-routing.handoff.enabled' => true, 'domain-routing.stamps.enabled' => true]);
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    $user = User::create(['name' => 'Bo']);
    Route::middleware('web')->get('/me', fn () => (string) (auth()->id() ?? 'guest'));

    $this->actingAs($user)->get('http://atannex.test/me')->assertSee((string) $user->id);

    app(SessionStamps::class)->revokeAll($user);

    $this->get('http://atannex.test/me')->assertSee('guest');
});
