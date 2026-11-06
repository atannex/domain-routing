<?php

declare(strict_types=1);

use Atannex\DomainRouting\Events\HandoffRejected;
use Atannex\DomainRouting\Exceptions\InvalidHostParameter;
use Atannex\DomainRouting\Handoff\Handoffs;
use Atannex\DomainRouting\Handoff\RejectionReason;
use Atannex\DomainRouting\Handoff\SessionStamps;
use Atannex\DomainRouting\Hosts\HostPattern;
use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Atannex\DomainRouting\Tests\Fixtures\User;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

function auditUsers(string $connection = 'testing'): void
{
    Schema::connection($connection)->create('users', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
}

it('refuses a forged issue link: only an exchange this session started can be issued', function () {
    $this->withSettings(['domain-routing.handoff.enabled' => true]);
    auditUsers();
    $victim = User::create(['name' => 'Victim']);
    Event::fake([HandoffRejected::class]);

    $forged = 'http://atannex.test/_handoff/issue?s='.hash('sha256', 'attacker-state').'&to='.urlencode('http://acme.app.test').'&r=/';
    $response = $this->actingAs($victim)->get($forged);

    $response->assertForbidden();
    expect((string) $response->headers->get('Location'))->not->toContain('t=');
    Event::assertDispatched(HandoffRejected::class, fn ($e) => $e->reason === RejectionReason::NotStarted);
});

it('hands off only the allowed guards', function () {
    $this->withSettings(['domain-routing.handoff.enabled' => true, 'domain-routing.handoff.guard' => 'web', 'auth.guards.admin' => ['driver' => 'session', 'provider' => 'users']]);
    auditUsers();
    $user = User::create(['name' => 'Ada']);
    Route::site('marketing', fn () => Route::get('/go', fn () => app(Handoffs::class)->to('http://acme.app.test/', 'admin')));

    $start = $this->actingAs($user, 'admin')->get($this->get('http://atannex.test/go')->headers->get('Location'));
    $issued = $this->get($start->headers->get('Location'));

    // admin is not in handoff.guards: sent on as a guest, no token.
    expect((string) $issued->headers->get('Location'))->toBe('http://acme.app.test/');
});

it('keeps remember-me and XSRF cookies host-only when a site shares its session domain', function () {
    $this->withSites(['app' => ['hosts' => ['app.atannex.test'], 'middleware' => ['web'], 'session' => ['domain' => '.atannex.test']]]);
    Route::site('app', fn () => Route::get('/set', function () {
        cookie()->queue('remember_web_x', 'secret', 60);

        return 'ok';
    }));

    $cookies = collect($this->get('http://app.atannex.test/set')->headers->getCookies())->mapWithKeys(fn ($c) => [$c->getName() => $c->getDomain()]);

    expect($cookies[config('session.cookie').'_app'])->toBe('.atannex.test')
        ->and($cookies['XSRF-TOKEN'])->toBeNull()
        ->and($cookies['remember_web_x'])->toBeNull();
});

it('names the session cookie per site even when the store was built earlier (long-running workers)', function () {
    $this->withSites([
        'm' => ['hosts' => ['atannex.test'], 'middleware' => ['web']],
        'app' => ['hosts' => ['app.atannex.test'], 'middleware' => ['web'], 'session' => ['cookie' => 'atx_app']],
    ]);
    Route::get('/s', fn () => 'ok')->middleware('web');
    app('session.store'); // warmed, as Octane does

    $this->get('http://atannex.test/s');
    $names = collect($this->get('http://app.atannex.test/s')->headers->getCookies())->map->getName();

    expect($names)->toContain('atx_app')->not->toContain('laravel_session');
});

it('keys session stamps by user table, so one tenant cannot sign out another tenant\'s user #1', function () {
    $file = sys_get_temp_dir().'/dr-second-'.getmypid().'.sqlite';
    touch($file);
    config(['database.connections.second' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '']]);
    auditUsers();
    auditUsers('second');
    $user = User::create(['name' => 'Ada']);

    app(SessionStamps::class)->revokeAll($user);
    expect(app(SessionStamps::class)->current('web', (string) $user->id))->not->toBe('0');

    // Same id, same guard, another database.
    config(['database.default' => 'second']);
    app('auth')->forgetGuards();
    expect(app(SessionStamps::class)->current('web', (string) $user->id))->toBe('0');
    unlink($file);
});

it('does not loop behind a proxy that rewrites Host and sends X-Forwarded-Host', function () {
    TrustProxies::at('*');
    Route::get('/page', EchoController::class);

    $this->get('http://backend.internal/page', ['X-Forwarded-Host' => 'atannex.test'])->assertOk()->assertJson(['site' => 'marketing']);
    TrustProxies::flushState();
});

it('refuses a host parameter with a trailing newline', function () {
    HostPattern::parse('{account}.app.test')->build(['account' => "acme\n"], 'app');
})->throws(InvalidHostParameter::class);

it('applies reserved labels whatever the constraint', function () {
    $this->withSites(['app' => ['hosts' => ['{account}.atannex.test'], 'where' => ['account' => '[a-z0-9-]+']]]);
    Route::site('app', fn () => Route::get('/x', EchoController::class));

    $this->get('http://www.atannex.test/x')->assertNotFound();
    $this->get('http://autodiscover.atannex.test/x')->assertNotFound();
    $this->get('http://acme.atannex.test/x')->assertOk();

    $this->withSites(['app' => ['hosts' => ['{account}.atannex.test'], 'reserved_labels' => false]]);
    Route::site('app', fn () => Route::get('/x', EchoController::class));
    $this->get('http://www.atannex.test/x')->assertOk();
});
