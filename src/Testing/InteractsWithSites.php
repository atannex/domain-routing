<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Testing;

use Atannex\DomainRouting\Hosts\HostBuilder;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Illuminate\Support\Facades\URL;

/** $this->onSite('app', ['account' => 'acme'])->get('/'): requests go to that site's host. */
trait InteractsWithSites
{
    /** @param array<array-key, mixed> $parameters */
    protected function onSite(string $site, array $parameters = [], ?string $host = null): static
    {
        $definition = app(SiteRegistry::class)->get($site);
        $builder = app(HostBuilder::class);

        URL::forceRootUrl($builder->rootFor($definition, $host ?? $builder->resolve($definition, $parameters)->host));

        return $this;
    }

    protected function onHost(string $host, string $scheme = 'http'): static
    {
        URL::forceRootUrl($scheme.'://'.$host);

        return $this;
    }
}
