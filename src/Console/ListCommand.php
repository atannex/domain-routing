<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Console;

use Atannex\DomainRouting\Routing\SiteValidator;
use Atannex\DomainRouting\Sites\SiteRegistry;
use Illuminate\Console\Command;

final class ListCommand extends Command
{
    protected $signature = 'domains:list {--json : Machine-readable output}';

    protected $description = 'List sites, their hosts in this environment and their routes';

    public function handle(SiteRegistry $sites): int
    {
        $counts = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $site = SiteValidator::siteOf($route->action) ?? '(any)';
            $counts[$site] = ($counts[$site] ?? 0) + 1;
        }

        $rows = [];

        foreach ($sites->all() as $site) {
            $rows[] = [
                'site' => $site->name,
                'hosts' => implode(', ', [...$site->hosts, ...($site->dynamic ? ['dynamic'] : [])]) ?: '—',
                'canonical' => $site->canonical ?? '—',
                'routes' => $counts[$site->name] ?? 0,
                'middleware' => implode(', ', $site->middleware) ?: '—',
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['sites' => $rows, 'unsited_routes' => $counts['(any)'] ?? 0], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(['Site', 'Hosts', 'Canonical', 'Routes', 'Middleware'], $rows);
        $this->line('Routes outside any site: '.($counts['(any)'] ?? 0));

        return self::SUCCESS;
    }
}
