<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Console;

use Atannex\DomainRouting\Edge\MaintenanceStore;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Illuminate\Console\Command;

final class UpCommand extends Command
{
    protected $signature = 'domains:up {site}';

    protected $description = 'Bring a site out of maintenance mode';

    public function handle(SiteRegistry $sites, MaintenanceStore $store): int
    {
        $site = $sites->get((string) $this->argument('site'))->name;
        $store->up($site);
        $this->components->info("Site [{$site}] is live.");

        return self::SUCCESS;
    }
}
