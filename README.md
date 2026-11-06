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

Routes outside any site (`routes/web.php`) follow `unsited_routes`:

| Mode | Where they answer |
| --- | --- |
| `exact` (default) | Sites matched by an exact host, and requests with no site. Never on `{account}.…` or dynamic (tenant) hosts, so central and admin routes can't leak onto a tenant's domain. |
| `static` | Also on pattern hosts, never on dynamic hosts. |
| `everywhere` / `none` | As named. |

A site's own `'unsited_routes' => true|false` wins. `shared_routes` (URI patterns, default Livewire, broadcasting, Sanctum, storage and `up`) answer everywhere. A site's own route always beats an unsited route with the same URI, and a site's fallback beats a global one, whatever order they're registered in.

`{account}` never matches a reserved label (`www`, `mail`, `ftp`… in `reserved_labels`), so `www.example.com` is never the account "www".

`{account}` is added to the route parameters, so `public function show(string $account)` receives it. Turn this off with `bind_host_parameters => false`.

Any host no site serves gets a 404 (`unmatched => abort`). The alternatives are `default` and `redirect:https://…`. With `default`, unknown hosts are served by the `default` site and links are pinned to its host; only a site with a plain canonical host can be the default, otherwise unknown hosts still get a 404. Paths in `except` (default `['up']`) skip resolution, so health checks on raw IPs keep working.

Override hosts per deployment with `DOMAIN_ROUTING_HOSTS="app=a.com,b.com;marketing=x.com"`. It's read in the config file, so it survives `config:cache`.

### Sessions

Session, remember-me and queued cookies stay on the exact host by default, even if `SESSION_DOMAIN` is shared (`isolate_sessions`). A site that sets `session.domain` shares its cookie on purpose, and gets its own cookie name (`laravel_session_{site}`) so sibling sites never load its session. Everything changed for a request is put back after the response.

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

Jobs remember the site they were dispatched from, and `url()`, `asset()` and unsited routes (password resets, email verification) in their mail point at that site. Use `DomainRouting::run('app', fn () => …, ['account' => 'acme'])` to work as a site from a command. In Blade: `@site('app') … @else … @endsite`.

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

// On site A, for the signed-in user:
return app(Handoffs::class)->to('https://acme-shop.com/orders'); // Atannex\DomainRouting\Handoff\Handoffs
```

It works in three hops, like OAuth's `state`:

1. A sends the browser to `B/_handoff/start`.
2. B sets a state cookie on its own host and sends the browser back to `A/_handoff/issue`.
3. A mints a token for the signed-in user, bound to B's host and the state's hash, and sends the browser to `B/_handoff?t=…`.

B then signs the user in only if the browser holds the matching state cookie. A token captured or minted by someone else is useless, so there's no login CSRF.

- Tokens are 256-bit, single use (atomic pull under a lock) and valid for 60 seconds. Only their SHA-256 is stored, in a cache that tenancy never prefixes.
- The token is bound to the target host and guard, and optionally to the IP or User-Agent.
- Both sides must use the same users table (a fingerprint of the user provider's database), so an id never names someone else on a tenant database.
- A different user already signed in on B is never replaced.
- Guests are sent on as guests. Targets must be hosts a site serves, landing paths must be relative, and `http` targets become `https` in production.
- The endpoints run the target site's own middleware (for example `tenant`, `web`).
- Use a cache store with locks: Redis, database or Memcached.

`stamps.enabled => true` adds a per-user session stamp, kept in an unprefixed store. `SessionStamps::revokeAll($user)` signs that user out on every domain and tenant, and cycles their remember-me token.

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
