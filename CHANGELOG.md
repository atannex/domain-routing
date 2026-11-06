# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Sites bound to exact, parameterised and dynamic hosts per environment, with a cacheable host index (`domains:cache`).
- Site-scoped routes (`Route::site()`, site route files, `#[Site]` for atannex/routing) that work with `route:cache`.
- Site-aware URL generation, `site_route()`, `site_url()`, `site_asset()`, signed URLs across sites, and site context carried into queued jobs.
- Canonical rules (HTTPS, www/apex, aliases, redirect maps) in one hop; 421/308 for non-GET requests.
- Per-site maintenance (`domains:down`/`domains:up`) and robots.txt.
- Cross-domain sign-in handoff and per-user session stamps.
- atannex/tenancy adapter (`TenancyHostProvider`, `TenancyHostParameters`).
- `domains:list`, `domains:resolve`, `domains:routes`, `domains:doctor`; testing helpers; `@site` Blade directive.
- Per-site `config`, `views` and Sanctum `stateful` hosts; HSTS; per-site rate-limit buckets; on-demand TLS ask endpoint; `redirect()->toSite()` / `siteRoute()`.
- atannex/tenancy alignment: `tenancy:central` hosts, shared impersonation/invitation routes, TLS ask path, reserved slugs, central guard option, same scheme as `tenant_route()`.

### Security
- Routes outside any site stay off pattern and dynamic hosts by default (`unsited_routes: exact`, `shared_routes`); a site's own routes and fallback always win.
- Session, remember-me and queued cookies are host-only unless a site shares them; shared session domains get their own cookie name.
- Handoff is a three-hop exchange bound to a state cookie on the target (no login CSRF), runs the target site's middleware, checks the user store matches, never replaces a signed-in user, and keeps tokens in an unprefixed store.
- Session stamps live in an unprefixed store, are written at login, and revoking cycles remember-me tokens.
- Reserved labels, a fixed host for the `default` site, mixed-case and trailing-dot hosts redirected, host constraints validated.
- Per-request state (site, cookie scope, forced root, log context) is restored after terminate.
