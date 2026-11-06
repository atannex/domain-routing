<?php

declare(strict_types=1);

namespace Atannex\DomainRouting\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\Matching\ValidatorInterface;
use Illuminate\Routing\Route;

/** @internal A site's routes match only on that site; unsited routes everywhere (or nowhere, when strict). */
final class SiteValidator implements ValidatorInterface
{
    public const string ATTRIBUTE = 'domain-routing.site';

    public static bool $strict = false;

    public function matches(Route $route, Request $request): bool
    {
        return self::allows(self::siteOf($route->action), $request);
    }

    public static function allows(?string $site, Request $request): bool
    {
        $current = $request->attributes->get(self::ATTRIBUTE);

        if ($site === null) {
            return ! self::$strict || $current === null;
        }

        return $current === $site;
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
