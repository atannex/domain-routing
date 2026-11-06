<?php

declare(strict_types=1);

use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

it('serves the same URI per site', function () {
    Route::site('marketing', fn () => Route::get('/', EchoController::class)->name('home'));
    Route::site('app', fn () => Route::get('/', [EchoController::class, 'other'])->name('home'));

    $this->get('http://atannex.test/')->assertJson(['controller' => 'echo', 'site' => 'marketing', 'route' => 'marketing.home']);
    $this->get('http://acme.app.test/')->assertJson(['controller' => 'other', 'site' => 'app']);
    $this->get('http://api.atannex.test/')->assertNotFound();
});

it('binds host parameters into route parameters', function () {
    Route::site('app', fn () => Route::get('/dashboard', EchoController::class));

    $this->get('http://acme.app.test/dashboard')->assertJson(['parameters' => ['account' => 'acme']]);
});

it('can skip host parameter binding', function () {
    $this->withSettings(['domain-routing.bind_host_parameters' => false]);
    Route::site('app', fn () => Route::get('/dashboard', EchoController::class));

    $this->get('http://acme.app.test/dashboard')->assertJson(['parameters' => []]);
});

it('serves unsited routes on static sites by default, or nowhere when told', function () {
    Route::get('/shared', EchoController::class);
    $this->get('http://api.atannex.test/shared')->assertOk();

    $this->withSettings(['domain-routing.unsited_routes' => 'none']);
    Route::get('/shared', EchoController::class);
    $this->get('http://api.atannex.test/shared')->assertNotFound();
    $this->get('http://10.0.0.1/shared')->assertNotFound(); // unknown host → abort
});

it('keeps fallback routes per site', function () {
    Route::site('marketing', fn () => Route::fallback(fn () => 'marketing fallback'));
    Route::site('api', fn () => Route::fallback(fn () => response('api fallback', 404)));

    $this->get('http://atannex.test/nope')->assertSee('marketing fallback');
    $this->get('http://api.atannex.test/nope')->assertNotFound()->assertSee('api fallback');
});

it('offers an @site Blade directive', function () {
    Route::site('api', fn () => Route::get('/blade', fn () => Blade::render('@site(\'api\') on api @else elsewhere @endsite')));
    Route::site('marketing', fn () => Route::get('/blade', fn () => Blade::render('@site(\'api\') on api @else elsewhere @endsite')));

    $this->get('http://api.atannex.test/blade')->assertSee('on api');
    $this->get('http://atannex.test/blade')->assertSee('elsewhere');
});

it('can leave route names unprefixed', function () {
    $this->withSettings(['domain-routing.route_names' => 'none']);
    Route::site('api', fn () => Route::get('/ping', EchoController::class)->name('ping'));

    $this->get('http://api.atannex.test/ping')->assertJson(['route' => 'ping']);
});

it('loads route files named in the site config', function () {
    $file = sys_get_temp_dir().'/dr-routes-'.getmypid().'.php';
    file_put_contents($file, '<?php use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route; Route::get("/from-file", '.EchoController::class.'::class)->name("file");');

    $this->withSites(['api' => ['hosts' => ['api.atannex.test'], 'routes' => [$file]]]);

    $this->get('http://api.atannex.test/from-file')->assertJson(['route' => 'api.file']);
    unlink($file);
});

it('applies site middleware', function () {
    $this->withSites(['api' => ['hosts' => ['api.atannex.test'], 'middleware' => ['auth']]]);
    Route::site('api', fn () => Route::get('/private', EchoController::class));
    Route::get('/login', fn () => 'login')->name('login');

    $this->get('http://api.atannex.test/private')->assertRedirect();
});
