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
- `domains:list`, `domains:resolve`, `domains:doctor`; testing helpers.
