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

function usersTable(): void
{
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
}

beforeEach(function () {
    $this->withSettings(['domain-routing.handoff.enabled' => true, 'domain-routing.stamps.enabled' => true]);
    usersTable();
    $this->user = User::create(['name' => 'Ada']);

    Route::site('marketing', fn () => Route::get('/go', fn () => app(Handoffs::class)->to((string) request('to'))));
    Route::site('app', fn () => Route::get('/me', fn () => (string) (auth()->id() ?? 'guest')));
});

/** Runs A → B/start → A/issue as one browser; returns [redeem URL, state cookie value]. */
function handoff(object $test, User $user, string $target = 'http://acme.app.test/me?tab=1'): array
{
    $test->actingAs($user);
    $toStart = $test->get('http://atannex.test/go?to='.urlencode($target))->assertRedirect();

    $start = $test->get($toStart->headers->get('Location'));

    while ($start->isRedirect() && ! str_contains((string) $start->headers->get('Location'), '/_handoff/issue')) {
        $start = $test->get($start->headers->get('Location'));
    }

    $state = $start->getCookie(Handoffs::COOKIE)?->getValue();
    $redeem = $test->get($start->headers->get('Location'))->assertRedirect()->headers->get('Location');

    app('auth')->forgetGuards(); // the target domain has its own session

    return [$redeem, $state];
}

function rejected(RejectionReason $reason): void
{
    Event::assertDispatched(HandoffRejected::class, fn ($e) => $e->reason === $reason);
}

it('signs the user in on the other domain, once', function () {
    [$redeem, $state] = handoff($this, $this->user);

    expect($redeem)->toStartWith('http://acme.app.test/_handoff?t=');

    $this->withCookie(Handoffs::COOKIE, $state)->get($redeem)
        ->assertRedirect('/me?tab=1')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->assertAuthenticatedAs($this->user);

    app('auth')->forgetGuards();
    $this->flushSession();
    Event::fake([HandoffRejected::class]);
    $this->withCookie(Handoffs::COOKIE, $state)->get($redeem)->assertForbidden();
    rejected(RejectionReason::ExpiredOrUsed);
});

it('refuses a token in a browser that did not start the exchange (login CSRF)', function () {
    $attacker = User::create(['name' => 'Mallory']);
    [$redeem] = handoff($this, $attacker); // the attacker's own token, sent to a victim

    Event::fake([HandoffRejected::class]);
    $this->get($redeem)->assertForbidden();
    $this->withCookie(Handoffs::COOKIE, 'victim-own-state')->get($redeem)->assertForbidden();

    rejected(RejectionReason::StateMismatch);
    $this->assertGuest();
});

it('never swaps a signed-in user for another', function () {
    [$redeem, $state] = handoff($this, $this->user);
    $other = User::create(['name' => 'Bo']);
    Event::fake([HandoffRejected::class]);

    $this->actingAs($other)->withCookie(Handoffs::COOKIE, $state)->get($redeem)->assertForbidden();

    rejected(RejectionReason::SessionConflict);
    $this->assertAuthenticatedAs($other);
});

it('sends guests on as guests', function () {
    $toStart = $this->get('http://atannex.test/go?to='.urlencode('http://acme.app.test/me'));
    $start = $this->get($toStart->headers->get('Location'));

    $this->get($start->headers->get('Location'))->assertRedirect('http://acme.app.test/me');
});

it('refuses targets and origins no site serves', function () {
    expect(fn () => app(Handoffs::class)->to('https://evil.example/'))->toThrow(InvalidHandoffTarget::class)
        ->and(fn () => app(Handoffs::class)->to('javascript://acme.app.test/'))->toThrow(InvalidHandoffTarget::class)
        ->and(fn () => app(Handoffs::class)->to('http://user@acme.app.test/'))->toThrow(InvalidHandoffTarget::class);

    $this->get('http://acme.app.test/_handoff/start?from='.urlencode('https://evil.example'))->assertForbidden();
    $this->get('http://atannex.test/_handoff/issue?s='.str_repeat('a', 64).'&to='.urlencode('https://evil.example'))->assertForbidden();
});

it('follows the canonical host of the target', function () {
    Route::site('marketing', fn () => Route::get('/me', fn () => (string) (auth()->id() ?? 'guest')));

    [$redeem, $state] = handoff($this, $this->user, 'http://www.atannex.test/me');

    expect($redeem)->toStartWith('http://atannex.test/_handoff?t=');
    $this->withCookie(Handoffs::COOKIE, $state)->get($redeem)->assertRedirect('/me');
    $this->assertAuthenticatedAs($this->user);
});

it('binds the token to its host', function () {
    [$redeem, $state] = handoff($this, $this->user);
    Event::fake([HandoffRejected::class]);

    $this->withCookie(Handoffs::COOKIE, $state)->get(str_replace('acme.app.test', 'other.app.test', $redeem))->assertForbidden();

    rejected(RejectionReason::HostMismatch);
    $this->assertGuest();
});

it('expires tokens', function () {
    [$redeem, $state] = handoff($this, $this->user);
    $this->travel(61)->seconds();

    $this->withCookie(Handoffs::COOKIE, $state)->get($redeem)->assertForbidden();
    $this->assertGuest();
});

it('rejects malformed tokens', function (string $token) {
    $this->get('http://acme.app.test/_handoff?t='.urlencode($token))->assertForbidden();
})->with(['', 'short', str_repeat('a', 44), str_repeat('*', 43)]);

it('binds to the IP when asked', function () {
    config(['domain-routing.handoff.bind_ip' => true]);
    [$redeem, $state] = handoff($this, $this->user);

    $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])->withCookie(Handoffs::COOKIE, $state)->get($redeem)->assertForbidden();
});

it('rejects users that no longer exist', function () {
    [$redeem, $state] = handoff($this, $this->user);
    $this->user->delete();

    $this->withCookie(Handoffs::COOKIE, $state)->get($redeem)->assertForbidden();
});

it('only redirects to same-host paths', function (string $path, string $expected) {
    expect(Handoffs::safePath($path))->toBe($expected);
})->with([
    ['/dashboard?x=1', '/dashboard?x=1'],
    ['//evil.com', '/'],
    ['/\\evil.com', '/'],
    ["/\t/evil.com", '/'],
    ['https://evil.com', '/'],
]);

it('signs out every session and every remember-me cookie when the stamp is revoked', function () {
    $this->actingAs($this->user)->get('http://acme.app.test/me')->assertSee((string) $this->user->id);
    $token = $this->user->fresh()->remember_token;

    app(SessionStamps::class)->revokeAll($this->user);

    $this->get('http://acme.app.test/me')->assertSee('guest');
    expect($this->user->fresh()->remember_token)->not->toBe($token);
});
