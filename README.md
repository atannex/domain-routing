# atannex/domain-routing

Multi-domain routing for Laravel. One app serves many domains (marketing site, app subdomains, API, admin, tenant custom domains), each with its own routes.

- **Sites**: named surfaces bound to hosts per environment: exact (`atannex.com`), parameterised (`{account}.atannex.com`) or `dynamic` (custom domains looked up through a `HostProvider`).
- **Site-scoped routes**: the same URI can exist on several sites, and keeps working with `route:cache`.
- **Cross-domain URLs**: `route()` roots every route at its own site's host, in requests, jobs and mail. Host values are validated, so they can't produce a foreign host.
- **Canonical rules**: HTTPS, www/apex, aliases to the canonical host and per-site redirect maps, in a single redirect hop.
- **Per-site maintenance** and **robots.txt**.
- **Cross-domain sign-in**: single-use, 60-second, host-bound handoff tokens, plus "sign out everywhere".
- **atannex/tenancy combo**: install both, and verified tenant domains become a dynamic site automatically.

Requires PHP 8.3+ and Laravel 12 or 13.

## Install

```bash
composer require atannex/domain-routing
php artisan vendor:publish --tag=domain-routing-config
```

## Define sites

```php
// config/domain-routing.php
'sites' => [
    'marketing' => [
        'hosts' => ['production' => ['atannex.com', 'www.atannex.com'], 'local' => ['atannex.test']],
        'routes' => [base_path('routes/marketing.php')],
        'middleware' => ['web'],
    ],
    'app' => [
        'hosts' => ['*' => ['{account}.app.atannex.com']],
        'routes' => [base_path('routes/app.php')],
        'middleware' => ['web', 'auth'],
        'session' => ['cookie' => 'atx_app'],
    ],
    'api' => ['hosts' => ['api.atannex.com'], 'middleware' => ['api']],
],
```

Routes in a site's files belong to that site and are named `{site}.{name}`. You can also declare them in code:

```php
Route::site('api', function () {
    Route::get('/status', StatusController::class)->name('status'); // api.status
});
```

Routes outside any site match on every site. Set `strict_routes => true` to make them match only when no site resolved.

`{account}` is added to the route parameters, so `public function show(string $account)` receives it. Turn this off with `bind_host_parameters => false`.

Any host no site serves gets a 404 (`unmatched => abort`). The alternatives are `default` (serve the `default` site; links are pinned to its host) and `redirect:https://…`. Paths in `except` (default `['up']`) skip resolution, so health checks on raw IPs keep working.

## URLs

```php
route('app.dashboard', ['account' => 'acme']);   // https://acme.app.atannex.com/dashboard
route('marketing.pricing');                       // https://atannex.com/pricing, from any site
site_route('app', 'dashboard', ['account' => 'acme']);
site_url('marketing', '/about');
site_asset('marketing', 'logo.svg');              // the site's asset_url when set
URL::signedRoute('app.invite', ['account' => 'acme']); // the signature covers the host
```

Inside a request on `acme.app.atannex.com`, `route('app.dashboard')` reuses `account`. A missing value throws `MissingHostParameter`, and a value that isn't one DNS label (or that breaks your `where` constraint) throws `InvalidHostParameter`.

Jobs remember the site they were dispatched from. Use `DomainRouting::run('app', fn () => …, ['account' => 'acme'])` to work as a site from a command.

## Canonical hosts

```php
'canonical' => ['https' => true, 'www' => 'strip', 'status' => 301, 'non_get' => 421, 'redirects' => []],
```

A site with `'canonical' => 'atannex.com'` sends its other exact hosts there. `redirects` maps paths (`'/old' => '/new'` or `['to' => 'https://…', 'status' => 302]`). Non-GET requests on a non-canonical URL get `421` (or `308`). The middleware runs after `TrustProxies`, so TLS terminated at a proxy doesn't loop.

## Maintenance and robots

```bash
php artisan domains:down app --secret=let-me-in --retry=120
php artisan domains:up app
```

Visit `/let-me-in` on that site to get a bypass cookie. Set `robots.enabled => true` to serve `/robots.txt` per site (`robots` key; anything but production defaults to `Disallow: /`).

## Cross-domain sign-in

```php
'handoff' => ['enabled' => true],

return Handoffs::to(auth()->user(), 'https://acme-shop.com/orders'); // Atannex\DomainRouting\Handoff\Handoffs
```

The token is single use, valid for 60 seconds, and bound to the user, the guard and the target host (optionally the IP). Only its SHA-256 is stored. Redemption takes an atomic lock and regenerates the session. Targets must be hosts a site serves, and landing paths must be relative. Use a cache store with locks (Redis, database, Memcached).

`stamps.enabled => true` adds a per-user session stamp; `SessionStamps::revokeAll($user)` signs that user out on every domain.

## With atannex/tenancy

```php
'sites' => [
    'central' => ['hosts' => ['atannex.com'], 'middleware' => ['web']],
    'tenant' => ['hosts' => ['dynamic'], 'routes' => [base_path('routes/tenant.php')], 'middleware' => ['web']],
],
```

Verified tenant domains and `{slug}.{central domain}` are served on `tenant`. Tenancy's `tenant` middleware is prepended to that site, and tenancy still identifies the tenant. Alias domains go to the primary in one hop. `route('tenant.orders', ['tenant' => $tenant])` links to the tenant's primary domain from anywhere. Tenancy itself needs no changes.

## With atannex/routing

```php
#[Site('api')]
final class StatusController { #[Get('/status', name: 'status')] public function __invoke() { … } }
```

## Your own host provider

Implement `Contracts\HostProvider` (host → `SiteMatch` for a `dynamic` site) and register it in `providers`. Implement `Contracts\HostParameters` for URL generation. Verify a provider with the `Testing\HostProviderContract` trait. Use `Testing\InteractsWithSites` for `$this->onSite('app', ['account' => 'acme'])->get('/')`.

## Commands

| Command | |
| --- | --- |
| `domains:list` | Sites, hosts in this environment, route counts |
| `domains:resolve {host}` | Which site serves a host, and why |
| `domains:cache` / `domains:clear` | Compile the host index (also run by `optimize`) |
| `domains:doctor` | Ambiguous hosts, missing hosts, stale cache, tenancy overlap, handoff store, unsited routes on tenant hosts; non-zero exit for CI |
| `domains:down {site}` / `domains:up {site}` | Per-site maintenance |

## Events

`SiteResolved`, `SiteNotResolved`, `CanonicalRedirected`, `HandoffIssued`, `HandoffRedeemed`, `HandoffRejected` (with a `RejectionReason`), `SessionStampRevoked`.

## License

MIT
