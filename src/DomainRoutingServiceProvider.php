<?php

declare(strict_types=1);

namespace Atannex\DomainRouting;

use Illuminate\Support\ServiceProvider;

final class DomainRoutingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/domain-routing.php', 'domain-routing');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/domain-routing.php' => config_path('domain-routing.php')], 'domain-routing-config');
    }
}
