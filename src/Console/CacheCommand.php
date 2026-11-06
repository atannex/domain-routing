<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Console;

use Atannex\DomainRouting\Hosts\HostIndex;
use Atannex\DomainRouting\Hosts\IndexCache;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Illuminate\Console\Command;

final class CacheCommand extends Command
{
    protected $signature = 'domains:cache';

    protected $description = 'Compile the host index into a cache file';

    public function handle(SiteRegistry $sites, IndexCache $cache): int
    {
        $cache->write(HostIndex::build($sites->all(), true), $sites->hash());
        $this->components->info('Host index cached.');

        return self::SUCCESS;
    }
}
