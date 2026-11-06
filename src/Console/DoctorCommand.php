<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Console;

use Atannex\DomainRouting\Hosts\HostIndex;
use Atannex\DomainRouting\Hosts\HostNormalizer;
use Atannex\DomainRouting\Hosts\IndexCache;
use Atannex\DomainRouting\Integrations\Tenancy\TenancyIntegration;
use Atannex\DomainRouting\Resolution\SiteResolver;
use Atannex\DomainRouting\Routing\SiteValidator;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Atannex\DomainRouting\Urls\SiteUrlGenerator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Throwable;

final class DoctorCommand extends Command
{
    protected $signature = 'domains:doctor {--strict : Fail on warnings too}';

    protected $description = 'Check the multi-domain setup for mistakes';

    /** @var list<array{0: string, 1: string}> */
    private array $results = [];

    public function handle(SiteRegistry $sites, SiteResolver $resolver, IndexCache $cache): int
    {
        // Report ambiguity instead of throwing on it, and read live config, not the cache file.
        $sites->useCache(null, false);

        if ($sites->isEmpty()) {
            $this->warning('No sites are defined; every request is served as before.');
        }

        $index = HostIndex::build($sites->all(), false);

        foreach ($index->conflicts as $conflict) {
            $this->failure($conflict);
        }

        foreach ($sites->all() as $site) {
            if (! $site->dynamic && $site->hosts === []) {
                $this->failure("Site [{$site->name}] has no hosts in the [".app()->environment().'] environment.');
            }

            foreach ($site->hosts as $host) {
                if (str_contains($host, 'xn--')) {
                    $this->warning("Site [{$site->name}] host [{$host}] is internationalised; check it for look-alike characters.");
                }
            }
        }

        $default = config('domain-routing.default');

        if (config('domain-routing.unmatched') === 'default') {
            if (! is_string($default) || ! $sites->has($default)) {
                $this->failure("unmatched is 'default' but default names no site.");
            } elseif ($sites->get($default)->canonicalHost() === null) {
                $this->failure("The default site [{$default}] needs a plain canonical host.");
            }
        }

        $appHost = HostNormalizer::normalize((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if (! $sites->isEmpty() && $appHost !== '' && $resolver->resolve($appHost) === null && config('domain-routing.unmatched') === 'abort') {
            $this->warning("APP_URL host [{$appHost}] is served by no site, so it will answer 404.");
        }

        if ($sites->hasDynamic() && config('domain-routing.unsited_routes', 'static') === 'everywhere') {
            $unsited = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => SiteValidator::siteOf($route->action) === null)->count();

            if ($unsited > 0) {
                $this->warning("{$unsited} route(s) belong to no site and are reachable on dynamic (tenant) hosts too; set unsited_routes to static or put them on a site.");
            }
        }

        if (TenancyIntegration::available()) {
            $tenantSite = TenancyIntegration::site();

            if (! $sites->has($tenantSite) || ! $sites->get($tenantSite)->dynamic) {
                $this->warning("atannex/tenancy is installed but site [{$tenantSite}] is missing or not dynamic, so tenant domains are not served.");
            }

            foreach ((array) config('tenancy.identification.central_domains', []) as $central) {
                $match = $resolver->resolve((string) $central);

                if ($match !== null && $match->name() === $tenantSite) {
                    $this->failure("Tenancy central domain [{$central}] resolves to the tenant site.");
                }
            }
        }

        if (config('domain-routing.handoff.enabled')) {
            try {
                $store = cache()->store(config('domain-routing.handoff.store'))->getStore();

                if (! $store instanceof LockProvider) {
                    $this->failure('The handoff cache store has no atomic locks; tokens could be redeemed twice.');
                } elseif (app()->environment('production') && $store instanceof ArrayStore) {
                    $this->failure('The handoff cache store is the array store; tokens would not survive between requests.');
                }
            } catch (Throwable $e) {
                $this->failure('The handoff cache store is unavailable: '.$e->getMessage());
            }
        }

        $names = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (($name = $route->getName()) !== null) {
                $names[$name][] = SiteValidator::siteOf($route->action) ?? '(no site)';
            }
        }

        foreach ($names as $name => $owners) {
            if (count($owners) > 1) {
                $this->failure("Route name [{$name}] is used ".count($owners).' times ('.implode(', ', array_unique($owners)).'); route() can only reach one of them.');
            }
        }

        if ($cache->exists() && $cache->storedHash() !== $sites->hash()) {
            $this->failure('The host index cache is stale; run php artisan domains:cache.');
        }

        if (! app('url') instanceof SiteUrlGenerator) {
            $this->failure("The 'url' binding was replaced after this package loaded; cross-site route() links will use the request host.");
        }

        if ($this->results === []) {
            $this->components->info('No problems found.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($this->results as [$level, $message]) {
            $level === 'fail' ? $this->components->error($message) : $this->components->warn($message);
            $failed = $failed || $level === 'fail' || $this->option('strict');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function failure(string $message): void
    {
        $this->results[] = ['fail', $message];
    }

    private function warning(string $message): void
    {
        $this->results[] = ['warn', $message];
    }
}
