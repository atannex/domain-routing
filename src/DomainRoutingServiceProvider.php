<?php

declare(strict_types=1);

namespace Atannex\DomainRouting;

use Atannex\DomainRouting\Edge\MaintenanceStore;
use Atannex\DomainRouting\Hosts\Extensions;
use Atannex\DomainRouting\Hosts\HostBuilder;
use Atannex\DomainRouting\Hosts\IndexCache;
use Atannex\DomainRouting\Http\Controllers\HandoffController;
use Atannex\DomainRouting\Http\Controllers\RobotsController;
use Atannex\DomainRouting\Http\Middleware\Canonicalize;
use Atannex\DomainRouting\Http\Middleware\EnsureSessionStamp;
use Atannex\DomainRouting\Http\Middleware\ResolveSite;
use Atannex\DomainRouting\Http\Middleware\SiteMaintenance;
use Atannex\DomainRouting\Integrations\Tenancy\TenancyIntegration;
use Atannex\DomainRouting\Queue\QueueContext;
use Atannex\DomainRouting\Resolution\SiteContext;
use Atannex\DomainRouting\Resolution\SiteResolver;
use Atannex\DomainRouting\Routing\BindHostParameters;
use Atannex\DomainRouting\Routing\SiteAwareCompiledRouteCollection;
use Atannex\DomainRouting\Routing\SiteAwareRouteCollection;
use Atannex\DomainRouting\Routing\SiteRoutes;
use Atannex\DomainRouting\Routing\SiteValidator;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Atannex\DomainRouting\Urls\SiteUrlGenerator;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class DomainRoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/domain-routing.php', 'domain-routing');

        $this->app->singleton(IndexCache::class, fn ($app) => new IndexCache($app->bootstrapPath('cache/domain-routing.php')));
        $this->app->singleton(MaintenanceStore::class, fn ($app) => new MaintenanceStore($app->storagePath('framework/domain-routing/down')));
        $this->app->singleton(Extensions::class);

        $this->app->singleton(SiteRegistry::class, function ($app): SiteRegistry {
            $registry = SiteRegistry::fromConfig((array) $app['config']->get('domain-routing.sites', []), (string) $app->environment());
            $registry->useCache($app->make(IndexCache::class), $app->environment('local', 'testing'));

            return $registry;
        });

        $this->app->singleton(SiteResolver::class);
        $this->app->scoped(SiteContext::class);
        $this->app->scoped(HostBuilder::class);
        $this->app->scoped(QueueContext::class);
        $this->app->singleton(DomainRouting::class);

        $this->registerUrlGenerator();

        // Lets ResolveSite put back whatever root the app forced before it.
        UrlGenerator::macro('domainRoutingForcedRoot', function (): ?string {
            /** @var UrlGenerator $this */
            return $this->forcedRoot;
        });

        $router = $this->app['router'];
        $routes = $router->getRoutes();

        if ($routes instanceof RouteCollection && ! $routes instanceof SiteAwareRouteCollection) {
            $router->setRoutes(SiteAwareRouteCollection::from($routes));
        }

        SiteValidator::register();
        SiteRoutes::macro();
    }

    public function boot(): void
    {
        $config = $this->app['config'];
        SiteValidator::$unsited = (string) $config->get('domain-routing.unsited_routes', SiteValidator::STATIC);
        SiteValidator::$shared = array_values(array_map('strval', (array) $config->get('domain-routing.shared_routes', [])));

        $extensions = $this->app->make(Extensions::class);

        foreach ((array) $config->get('domain-routing.providers', []) as $provider) {
            $extensions->addProvider($provider);
        }

        foreach ((array) $config->get('domain-routing.parameters', []) as $parameters) {
            $extensions->addParameters($parameters);
        }

        if (TenancyIntegration::available()) {
            TenancyIntegration::register($extensions);
        }

        $this->registerMiddleware();
        $this->registerRoutes();
        $this->registerQueueContext();

        Event::listen(RouteMatched::class, function (RouteMatched $event): void {
            if (config('domain-routing.bind_host_parameters', true)) {
                $this->app->make(BindHostParameters::class)->handle($event);
            }
        });

        // After every provider has booted, so sites defined in the app's own providers count too.
        $this->app->booted(function (): void {
            $sites = $this->app->make(SiteRegistry::class);

            EncryptCookies::except(array_map(fn ($site) => MaintenanceStore::cookie($site), array_keys($sites->all())));

            if (! $this->app->routesAreCached()) {
                SiteRoutes::load($this->app['router'], $sites);
            }

            $sites->freeze();
        });

        $this->callAfterResolving('blade.compiler', function ($blade): void {
            $blade->if('site', fn (string ...$names) => $this->app->make(SiteContext::class)->is(...$names));
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/domain-routing.php' => config_path('domain-routing.php')], 'domain-routing-config');

            $this->commands([
                Console\ListCommand::class,
                Console\ResolveCommand::class,
                Console\CacheCommand::class,
                Console\ClearCommand::class,
                Console\DoctorCommand::class,
                Console\DownCommand::class,
                Console\UpCommand::class,
            ]);

            $this->optimizes(optimize: 'domains:cache', clear: 'domains:clear', key: 'domain-routing');
        }
    }

    /** Laravel's own registration, with the site-aware generator. Extenders (session, keys, routes) still apply. */
    private function registerUrlGenerator(): void
    {
        $this->app->singleton('url', function ($app) {
            $routes = $app['router']->getRoutes();
            $app->instance('routes', $routes);

            return new SiteUrlGenerator(
                $routes,
                $app->rebinding('request', fn ($app, $request) => $app['url']->setRequest($request)),
                $app['config']['app.asset_url'],
            );
        });

        // route:cache swaps in a CompiledRouteCollection, whose matcher skips validators.
        Router::macro('useSiteAwareRoutes', function (SiteAwareCompiledRouteCollection $routes): void {
            /** @var Router $this */
            $this->routes = $routes;
            $this->container->instance('routes', $routes);
        });

        $this->app->rebinding('routes', function ($app, $routes): void {
            if ($routes instanceof CompiledRouteCollection && ! $routes instanceof SiteAwareCompiledRouteCollection) {
                $app['router']->useSiteAwareRoutes(SiteAwareCompiledRouteCollection::from($routes));
            }
        });
    }

    private function registerMiddleware(): void
    {
        $kernel = $this->app->make(Kernel::class);

        // Appended: after TrustProxies (so scheme and host are the client's), before routing and sessions.
        // @phpstan-ignore instanceof.alwaysTrue (always true under Testbench; apps may bind their own kernel)
        if ($kernel instanceof HttpKernel) {
            foreach ([ResolveSite::class, Canonicalize::class, SiteMaintenance::class] as $middleware) {
                $kernel->pushMiddleware($middleware);
            }
        }

        $router = $this->app['router'];
        $router->aliasMiddleware('site.stamp', EnsureSessionStamp::class);

        if ($this->app['config']->get('domain-routing.stamps.enabled')) {
            $router->pushMiddlewareToGroup('web', EnsureSessionStamp::class);
        }
    }

    private function registerRoutes(): void
    {
        $config = $this->app['config'];
        $router = $this->app['router'];

        // The package's own endpoints answer on every site, dynamic ones included.
        if ($config->get('domain-routing.handoff.enabled')) {
            $router->group(['site' => SiteValidator::ANY], fn () => $router->get(trim((string) $config->get('domain-routing.handoff.path', '_handoff'), '/'), HandoffController::class)
                ->middleware((array) $config->get('domain-routing.handoff.middleware', ['web']))
                ->name('domain-routing.handoff'));
        }

        if ($config->get('domain-routing.robots.enabled')) {
            $router->group(['site' => SiteValidator::ANY], fn () => $router->get('robots.txt', RobotsController::class)->name('domain-routing.robots'));
        }
    }

    private function registerQueueContext(): void
    {
        Queue::createPayloadUsing(fn () => $this->app->make(QueueContext::class)->payload());

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $this->app->make(QueueContext::class)->enter($event->job));
        Event::listen([JobProcessed::class, JobExceptionOccurred::class], fn () => $this->app->make(QueueContext::class)->leave());
    }
}
