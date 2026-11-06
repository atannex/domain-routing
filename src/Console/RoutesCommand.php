<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Console;

use Atannex\DomainRouting\Routing\SiteValidator;
use Illuminate\Console\Command;

final class RoutesCommand extends Command
{
    protected $signature = 'domains:routes {site? : Only this site; "-" for routes outside any site}';

    protected $description = 'List routes with the site they answer on';

    public function handle(): int
    {
        $filter = $this->argument('site');
        $rows = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $site = SiteValidator::siteOf($route->action);
            $label = $site === SiteValidator::ANY ? '(every site)' : ($site ?? '(no site)');

            if ($filter !== null && $filter !== ($site ?? '-')) {
                continue;
            }

            $rows[] = [$label, implode('|', $route->methods()), '/'.ltrim($route->uri(), '/'), $route->getName() ?? '', $route->getActionName()];
        }

        $this->table(['Site', 'Methods', 'URI', 'Name', 'Action'], $rows);

        return self::SUCCESS;
    }
}
