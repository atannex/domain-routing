<?php

declare(strict_types=1);

use Atannex\DomainRouting\Routing\SiteAwareCompiledRouteCollection;
use Atannex\DomainRouting\Tests\Fixtures\Controllers\EchoController;
use Illuminate\Support\Facades\Route;

/** What route:cache does, in-process: prepare, compile, load the compiled routes. */
function cacheRoutes(): void
{
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();
    $routes->refreshActionLookups();

    foreach ($routes as $route) {
        $route->prepareForSerialization();
    }

    $router->setCompiledRoutes($routes->compile());
}

it('keeps sites apart with route:cache', function () {
    Route::site('marketing', fn () => Route::get('/', EchoController::class)->name('home'));
    Route::site('app', fn () => Route::get('/', [EchoController::class, 'other'])->name('home'));
    Route::get('/shared', EchoController::class)->name('shared');

    cacheRoutes();

    expect(app('router')->getRoutes())->toBeInstanceOf(SiteAwareCompiledRouteCollection::class);

    $this->get('http://atannex.test/')->assertJson(['controller' => 'echo']);
    $this->get('http://acme.app.test/')->assertJson(['controller' => 'other']);
    $this->get('http://acme.app.test/')->assertJson(['controller' => 'other']);
    $this->get('http://api.atannex.test/')->assertNotFound();
    $this->get('http://api.atannex.test/shared')->assertOk();
    expect(route('app.home', ['account' => 'zed']))->toBe('http://zed.app.test');
});
