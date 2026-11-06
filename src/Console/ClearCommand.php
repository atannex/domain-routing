<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Console;

use Atannex\DomainRouting\Hosts\IndexCache;
use Illuminate\Console\Command;

final class ClearCommand extends Command
{
    protected $signature = 'domains:clear';

    protected $description = 'Remove the host index cache file';

    public function handle(IndexCache $cache): int
    {
        $cache->clear();
        $this->components->info('Host index cache cleared.');

        return self::SUCCESS;
    }
}
