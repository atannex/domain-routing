<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Routing;

use Atannex\DomainRouting\Resolution\SiteMatch;
use Illuminate\Http\Request;
use Illuminate\Routing\Matching\ValidatorInterface;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * @internal A site's routes match only on that site. Routes outside any site
 * follow `unsited_routes`: everywhere, static (not on dynamic hosts such as
 * tenant custom domains, except the shared paths), or none.
 */
final class SiteValidator implements ValidatorInterface
{
    public const string ATTRIBUTE = 'domain-routing.site';

    public const string SOURCE = 'domain-routing.source';

    /** Marks a route that belongs on every site (the package's own endpoints). */
    public const string ANY = '*';

    public const string EVERYWHERE = 'everywhere';

    public const string STATIC = 'static';

    public const string NONE = 'none';

    public static string $unsited = self::STATIC;

    /** @var list<string> URI patterns of unsited routes that are also served on dynamic hosts */
    public static array $shared = [];

    public function matches(Route $route, Request $request): bool
    {
        return self::allows($route, $request);
    }

    public static function allows(Route $route, Request $request): bool
    {
        $site = self::siteOf($route->action);

        if ($site === self::ANY) {
            return true;
        }

        $current = $request->attributes->get(self::ATTRIBUTE);

        if ($site !== null) {
            return $current === $site;
        }

        if ($current === null) {
            return true;
        }

        return match (self::$unsited) {
            self::EVERYWHERE => true,
            self::NONE => false,
            default => $request->attributes->get(self::SOURCE) !== SiteMatch::PROVIDER || Str::is(self::$shared, $route->uri()),
        };
    }

    /** @param array<string, mixed> $action */
    public static function siteOf(array $action): ?string
    {
        $site = $action['site'] ?? $action['routing']['meta']['site'] ?? null;

        // Nested Route::site() groups merge recursively; the innermost wins.
        if (is_array($site)) {
            $site = end($site);
        }

        return is_string($site) && $site !== '' ? $site : null;
    }

    public static function register(): void
    {
        foreach (Route::getValidators() as $validator) {
            if ($validator instanceof self) {
                return;
            }
        }

        Route::$validators = [...Route::getValidators(), new self];
    }
}
