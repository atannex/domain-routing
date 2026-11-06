<?php

declare(strict_types=1);

return [
    /*
     * Named sites. hosts: a list for every environment, or a map of
     * environment => list with '*' as the fallback. A host is exact
     * ("atannex.com"), parameterised ("{account}.atannex.com") or the word
     * "dynamic" (asked of HostProviders, e.g. verified tenant domains).
     * Override hosts per deployment with DOMAIN_ROUTING_HOSTS="app=a.com,b.com;marketing=x.com"
     * (read here, so it survives config:cache).
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
     *     'unsited_routes' => null, // true/false: do routes outside any site answer here?
     * ],
     */
    'sites' => [],

    'host_overrides' => env('DOMAIN_ROUTING_HOSTS'),

    // A default {parameter} never matches these labels, so www.example.com is never account "www".
    'reserved_labels' => ['www', 'mail', 'smtp', 'imap', 'pop', 'ftp', 'ns1', 'ns2', 'mx', 'webmail', 'autodiscover', 'cpanel'],

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
     * Routes outside any site (routes/web.php): where do they answer?
     *   exact (default): on sites matched by an exact host, and when no site resolved;
     *                    never on {account}.example.com or dynamic (tenant) hosts.
     *   static:          also on pattern hosts, never on dynamic hosts.
     *   everywhere | none.
     * A site's own 'unsited_routes' => true|false wins. shared_routes (URI patterns)
     * always answer, e.g. Livewire and broadcasting endpoints.
     */
    'unsited_routes' => 'exact',

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
        'state_ttl' => 300,
        'guard' => null,
        'bind_ip' => false,
        'bind_user_agent' => false,
        // A cache store with atomic locks (redis, database, memcached). Never tenant-prefixed.
        'store' => null,
        // Added after the site's own middleware (web, tenant…), which always runs first.
        'middleware' => ['throttle:60,1'],
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
