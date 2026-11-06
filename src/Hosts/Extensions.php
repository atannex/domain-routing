<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Hosts;

use Atannex\DomainRouting\Contracts\HostParameters;
use Atannex\DomainRouting\Contracts\HostProvider;
use Illuminate\Contracts\Container\Container;

/** @internal Registered HostProviders and HostParameters, resolved on first use. */
final class Extensions
{
    /** @var list<HostProvider|class-string<HostProvider>> */
    private array $providers = [];

    /** @var list<HostParameters|class-string<HostParameters>> */
    private array $parameters = [];

    public function __construct(private readonly Container $app) {}

    /** @param HostProvider|class-string<HostProvider> $provider */
    public function addProvider(HostProvider|string $provider): void
    {
        if (! in_array($provider, $this->providers, true)) {
            $this->providers[] = $provider;
        }
    }

    /** @param HostParameters|class-string<HostParameters> $parameters */
    public function addParameters(HostParameters|string $parameters): void
    {
        if (! in_array($parameters, $this->parameters, true)) {
            $this->parameters[] = $parameters;
        }
    }

    /** @return list<HostProvider> */
    public function providers(): array
    {
        return $this->providers = array_map(fn ($p) => is_string($p) ? $this->app->make($p) : $p, $this->providers);
    }

    /** @return list<HostParameters> */
    public function parameters(): array
    {
        return $this->parameters = array_map(fn ($p) => is_string($p) ? $this->app->make($p) : $p, $this->parameters);
    }
}
