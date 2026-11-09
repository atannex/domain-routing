<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Sites;

use Atannex\DomainRouting\Exceptions\SitesFrozen;
use Atannex\DomainRouting\Exceptions\UnknownSite;
use Atannex\DomainRouting\Hosts\HostIndex;
use Atannex\DomainRouting\Hosts\IndexCache;

/** Every site the app serves. Frozen once the app has booted. */
final class SiteRegistry
{
    /** @var array<string, Site> */
    private array $sites = [];

    private bool $frozen = false;

    private ?HostIndex $index = null;

    private ?IndexCache $cache = null;

    private bool $strict = true;

    /** @param list<Site> $sites */
    public function __construct(array $sites = [])
    {
        foreach ($sites as $site) {
            $this->sites[$site->name] = $site;
        }
    }

    /** @var array<string, list<string>> */
    private array $overrides = [];

    /**
     * @param  array<string, array<string, mixed>>  $sites
     * @param  array<string, list<string>>  $overrides
     */
    public static function fromConfig(array $sites, string $environment, array $overrides = []): self
    {
        $registry = new self;
        $registry->overrides = $overrides;

        foreach ($sites as $name => $config) {
            $registry->define((string) $name, (array) $config, $environment);
        }

        return $registry;
    }

    /** @param array<string, mixed> $config */
    public function define(string $name, array $config, string $environment): Site
    {
        if ($this->frozen) {
            throw SitesFrozen::define($name);
        }

        $this->index = null;

        return $this->sites[$name] = Site::fromArray($name, $config, $environment, $this->overrides);
    }

    public function replace(Site $site): void
    {
        if ($this->frozen) {
            throw SitesFrozen::define($site->name);
        }

        $this->index = null;
        $this->sites[$site->name] = $site;
    }

    public function get(string $name): Site
    {
        return $this->sites[$name] ?? throw UnknownSite::named($name);
    }

    public function has(string $name): bool
    {
        return isset($this->sites[$name]);
    }

    /** @return array<string, Site> */
    public function all(): array
    {
        return $this->sites;
    }

    public function isEmpty(): bool
    {
        return $this->sites === [];
    }

    public function hasDynamic(): bool
    {
        foreach ($this->sites as $site) {
            if ($site->dynamic) {
                return true;
            }
        }

        return false;
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function frozen(): bool
    {
        return $this->frozen;
    }

    /** Fingerprint of everything the host index is built from. */
    public function hash(): string
    {
        return md5((string) json_encode(array_map(fn (Site $s) => [$s->hosts, $s->where, $s->dynamic, $s->ports], $this->sites)));
    }

    /** Where the compiled index lives and whether ambiguity throws (local/testing) or is only recorded. */
    public function useCache(?IndexCache $cache, bool $strict): void
    {
        $this->cache = $cache;
        $this->strict = $strict;
        $this->index = null;
    }

    public function index(): HostIndex
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = $this->cache?->load($this->hash()) ?? HostIndex::build($this->sites, $this->strict);

        if ($index->conflicts !== [] && function_exists('app') && app()->bound('log')) {
            foreach ($index->conflicts as $conflict) {
                app('log')->critical('[domain-routing] '.$conflict);
            }
        }

        return $this->index = $index;
    }
}
