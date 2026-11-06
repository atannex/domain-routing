<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Hosts;

use Atannex\DomainRouting\Exceptions\MissingHostParameter;
use Atannex\DomainRouting\Resolution\SiteContext;
use Atannex\DomainRouting\Sites\Site;
use Illuminate\Contracts\Foundation\Application;

/** @internal Site + parameters → host and root URL, inside or outside a request. */
final class HostBuilder
{
    public function __construct(
        private readonly Application $app,
        private readonly SiteContext $context,
        private readonly Extensions $extensions,
    ) {}

    public function scheme(Site $site): string
    {
        $scheme = $site->scheme ?? config('domain-routing.scheme');

        if (is_string($scheme) && $scheme !== '') {
            return $scheme;
        }

        if ($this->app->environment('production')) {
            return 'https';
        }

        return $this->app->bound('request') && ! $this->app->runningInConsole() ? $this->app['request']->getScheme() : 'http';
    }

    /** @param array<array-key, mixed> $parameters */
    public function resolve(Site $site, array $parameters = []): ResolvedHost
    {
        foreach ($this->extensions->parameters() as $provider) {
            if (($resolved = $provider->hostFor($site, $parameters)) !== null) {
                return new ResolvedHost(HostNormalizer::normalize($resolved->host), $resolved->consumed);
            }
        }

        $current = $this->context->current();
        $same = $current !== null && $current->name() === $site->name;

        if ($site->dynamic && $site->hosts === []) {
            if ($same) {
                return new ResolvedHost($current->canonicalHost ?? $current->host);
            }

            throw MissingHostParameter::dynamic($site->name);
        }

        $pattern = $site->canonicalPattern() ?? throw MissingHostParameter::noHosts($site->name);
        $values = [];
        $consumed = [];

        foreach ($pattern->parameters as $name) {
            if (array_key_exists($name, $parameters)) {
                $values[$name] = $parameters[$name];
                $consumed[] = $name;
            } elseif ($same && array_key_exists($name, $current->parameters)) {
                $values[$name] = $current->parameters[$name];
            } else {
                throw MissingHostParameter::for($site->name, $name);
            }
        }

        return new ResolvedHost($pattern->build($values, $site->name), $consumed);
    }

    /** @param array<array-key, mixed> $parameters */
    public function root(Site $site, array $parameters = []): string
    {
        return $this->rootFor($site, $this->resolve($site, $parameters)->host);
    }

    public function rootFor(Site $site, string $host): string
    {
        return $this->scheme($site).'://'.$host.($site->port ? ':'.$site->port : '');
    }
}
