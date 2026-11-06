<?php

declare(strict_types=1);

return [
    /*
     * Named sites. hosts: a list for every environment, or a map of
     * environment => list with '*' as the fallback. A host is exact
     * ("atannex.com"), parameterised ("{account}.atannex.com") or the word
     * "dynamic" (asked of HostProviders, e.g. verified tenant domains).
     * Override a site's hosts with DOMAIN_ROUTING_HOSTS_{SITE}=a.com,b.com.
     *
     * 'marketing' => [
     *     'hosts' => ['production' => ['atannex.com', 'www.atannex.com'], 'local' => ['atannex.test']],
     *     'canonical' => 'atannex.com',
     *     'routes' => [base_path('routes/marketing.php')],
     *     'middleware' => ['web'],
     *     'where' => [],            // host parameter constraints
     *     'scheme' => null,         // null = global scheme
     *     'port' => null,
     *     'session' => [],          // cookie, domain, path, secure, same_site
     *     'redirects' => [],        // '/old' => '/new' | ['to' => 'https://x', 'status' => 302]
     *     'robots' => null,         // robots.txt body; null = allow in production, disallow elsewhere
     *     'asset_url' => null,
     *     'meta' => [],             // free-form (locale, brand…)
     * ],
     */
    'sites' => [],

    // Site used when no host matches and 'unmatched' is 'default'.
    'default' => null,

    // abort (404) | default | redirect:https://example.com
    'unmatched' => 'abort',

    // Paths that skip site resolution entirely (health checks).
    'except' => ['up'],

    // null = https in production, the request's scheme elsewhere.
    'scheme' => env('DOMAIN_ROUTING_SCHEME'),

    'canonical' => [
        'https' => true,
        'www' => 'strip',      // strip | add | null
        'status' => 301,
        'non_get' => 421,      // 421 Misdirected Request | 308
        'redirects' => [],     // applied on every site after the site's own map
    ],

    /*
     * Routes outside any site: everywhere | static | none.
     * static (default) serves them on exact and pattern hosts but never on
     * dynamic hosts (tenant custom domains), so central and admin routes can't
     * leak onto a tenant's domain. shared_routes are the exceptions (URI patterns).
     */
    'unsited_routes' => 'static',

    'shared_routes' => ['livewire*', 'broadcasting/*', 'sanctum/*', 'storage/*', 'up'],

    // Session cookies stay on the exact host unless a site sets session.domain.
    'isolate_sessions' => true,

    // prefix ("app.dashboard") | none
    'route_names' => 'prefix',

    // Add {account}-style host values to route parameters (static patterns only).
    'bind_host_parameters' => true,

    'handoff' => [
        'enabled' => false,
        'path' => '_handoff',
        'ttl' => 60,
        'bind_ip' => false,
        'bind_user_agent' => true,
        'store' => null,
        'middleware' => ['web', 'throttle:60,1'],
    ],

    // Per-user session stamp so "sign out everywhere" reaches every domain.
    'stamps' => [
        'enabled' => false,
        'guards' => ['web'],
        'store' => null,
    ],

    'maintenance' => [
        'except' => ['up'],
    ],

    'robots' => [
        'enabled' => false,
    ],

    // Extra HostProvider / HostParameters classes. Adapters add their own.
    'providers' => [],
    'parameters' => [],

    'integrations' => [
        'tenancy' => ['enabled' => true, 'site' => 'tenant', 'middleware' => 'tenant'],
        'routing' => ['enabled' => true],
    ],

    // Adds X-Site: <name> to responses. Keep off in production.
    'debug_header' => false,
];
