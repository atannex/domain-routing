<?php

declare(strict_types=1);

use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Illuminate\Support\Facades\Route;

it('answers the TLS ask for served hosts only, from any host', function () {
    $this->withSettings(['domain-routing.tls_ask.enabled' => true]);

    $this->get('http://127.0.0.1/domain-routing/tls/ask?domain=www.atannex.test')->assertOk();
    $this->get('http://127.0.0.1/domain-routing/tls/ask?domain=acme.app.test')->assertOk();
    $this->get('http://127.0.0.1/domain-routing/tls/ask?domain=evil.example')->assertNotFound();
    $this->get('http://127.0.0.1/domain-routing/tls/ask?domain=www.app.test')->assertNotFound();
    $this->get('http://127.0.0.1/domain-routing/tls/ask')->assertNotFound();
});

it('sends HSTS on secure responses when configured', function () {
    $this->withSites(['m' => ['hosts' => ['atannex.test'], 'scheme' => 'https']], ['domain-routing.canonical.hsts' => 'max-age=31536000']);
    Route::get('/page', EchoController::class);

    $this->get('https://atannex.test/page')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

it('keeps rate-limit buckets apart per site', function () {
    Route::site('marketing', fn () => Route::middleware('throttle:1,1')->get('/limited', fn () => 'm'));
    Route::site('api', fn () => Route::middleware('throttle:1,1')->get('/limited', fn () => 'a'));

    $this->get('http://atannex.test/limited')->assertOk();
    $this->get('http://atannex.test/limited')->assertStatus(429);
    $this->get('http://api.atannex.test/limited')->assertOk();
});

it('applies per-site config, views and sanctum stateful hosts for the request only', function () {
    $views = sys_get_temp_dir().'/dr-views-'.getmypid();
    @mkdir($views);
    file_put_contents($views.'/greeting.blade.php', 'Hello from the shop');

    $this->withSites(['shop' => ['hosts' => ['shop.test'], 'config' => ['app.name' => 'Shop'], 'views' => [$views], 'stateful' => true]],
        ['sanctum.stateful' => ['localhost']]);
    Route::get('/info', fn () => response()->json([config('app.name'), view('greeting')->render(), config('sanctum.stateful')]));

    $before = config('app.name');
    $this->get('http://shop.test/info')->assertExactJson(['Shop', 'Hello from the shop', ['localhost', 'shop.test']]);

    expect(config('app.name'))->toBe($before)
        ->and(config('sanctum.stateful'))->toBe(['localhost'])
        ->and(view()->exists('greeting'))->toBeFalse();

    unlink($views.'/greeting.blade.php');
    rmdir($views);
});

it('redirects to other sites', function () {
    Route::site('app', fn () => Route::get('/dashboard', EchoController::class)->name('dashboard'));
    Route::get('/a', fn () => redirect()->toSite('marketing', '/pricing'));
    Route::get('/b', fn () => redirect()->siteRoute('app', 'dashboard', ['account' => 'acme']));

    $this->get('http://atannex.test/a')->assertRedirect('http://atannex.test/pricing');
    $this->get('http://atannex.test/b')->assertRedirect('http://acme.app.test/dashboard');
});

it('lists routes per site', function () {
    Route::site('api', fn () => Route::get('/status', EchoController::class)->name('status'));
    Route::get('/plain', EchoController::class);

    $this->artisan('domains:routes', ['site' => 'api'])->expectsOutputToContain('api.status')->assertSuccessful();
    $this->artisan('domains:routes', ['site' => '-'])->expectsOutputToContain('/plain')->assertSuccessful();
});
