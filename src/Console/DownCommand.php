<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Console;

use Atannex\DomainRouting\Edge\MaintenanceStore;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Illuminate\Console\Command;

final class DownCommand extends Command
{
    protected $signature = 'domains:down {site} {--secret= : Path that sets a bypass cookie} {--retry= : Retry-After seconds} {--status=503}';

    protected $description = 'Put one site into maintenance mode';

    public function handle(SiteRegistry $sites, MaintenanceStore $store): int
    {
        $site = $sites->get((string) $this->argument('site'))->name;

        $store->down($site, [
            'secret' => $this->option('secret') ? trim((string) $this->option('secret'), '/') : null,
            'retry' => $this->option('retry') ? (int) $this->option('retry') : null,
            'status' => (int) $this->option('status'),
        ]);

        $this->components->warn("Site [{$site}] is now in maintenance mode.");

        return self::SUCCESS;
    }
}
