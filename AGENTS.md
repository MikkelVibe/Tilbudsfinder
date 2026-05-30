# AGENTS.md

## Project Context

Tilbudsfinder v2 is a rewrite of the original TilbudsAvisScraper project. The goal is to build a reliable grocery offer discovery website that aggregates current weekly grocery discounts, normalizes prices and units, and lets users quickly search and compare active deals.

The original v1 was built during studies using C#/.NET and Blazor. It had useful domain ideas but too much school-era bloat, brittle scraping, unreliable scheduling, no meaningful cache strategy, and an unattractive frontend. V2 should keep the product insight but rebuild the architecture around reliability, observability, and maintainability.

Old repo for reference only: `https://github.com/MikkelVibe/TilbudsAvisScraper`.

Do not port v1 architecture directly. Use it only to understand prior scraper/domain behavior.

## Product Direction

V2 is primarily a public deal-search website first.

MVP should focus on:

- Reliable grocer imports
- Active deal search and browsing
- Clean public website UX
- Product detail pages where matching confidence is high
- Unit-price normalization where reliable
- Price history where product matching is trustworthy
- Minimal admin/ops visibility
- Read-only API foundations for a future mobile app

Defer until later:

- User accounts
- Saved products
- Personalized price alerts
- Native mobile app
- Full manual product matching UI
- Dedicated search engine such as Meilisearch

## Tech Stack

- Backend: Laravel 13
- Frontend: Inertia + Vue 3
- Styling: Tailwind CSS 4
- Database: PostgreSQL
- Cache/queue/locks: Redis
- Search MVP: PostgreSQL full-text search and `pg_trgm`
- Queue monitoring target: Laravel Horizon
- Deployment target: Docker Compose on a cheap VPS
- CI/CD target: GitHub Actions building/pushing images to GHCR, then SSH deploy to VPS
- Scraper execution target: old laptop in apartment as pull-based scraper agent
- Alerts: email via Laravel notifications initially
- Tests: PHPUnit currently, Pest is acceptable if deliberately introduced later

## Local Development

This machine does not currently have system PHP or Composer available. The project includes local wrappers around a downloaded FrankenPHP binary in `.tools`. PostgreSQL and Redis run through Docker Compose.

Start local services:

```bash
docker compose up -d postgres redis
source scripts/activate
```

Run the app:

```bash
./scripts/serve-local
```

Open:

```text
http://127.0.0.1:8080
```

Run frontend dev server in a second terminal when needed:

```bash
npm run dev
```

Useful commands:

```bash
php artisan about
php artisan migrate
php vendor/bin/phpunit
composer install --no-scripts
npm install
npm run build
```

Do not rely on `php artisan test` with the local FrankenPHP wrapper. FrankenPHP reports an empty `PHP_BINARY`, which prevents Laravel from spawning PHPUnit. Use:

```bash
php vendor/bin/phpunit
```

## Git

This is a new v2 repository, separate from the old school project.

Remote:

```text
git@github.com:MikkelVibe/Tilbudsfinder.git
```

Main branch:

```text
main
```

## Architecture Principles

Use a Laravel monolith with clean internal boundaries. Do not split into microservices early.

Logical boundaries:

- Public website
- Read-only public API
- Admin/ops dashboard
- Import scheduler
- Scraper job API
- Scraper agent
- Normalization and matching pipeline
- Cache invalidation layer

The VPS should be the source of truth for scheduling, leases, retries, imports, validation, alerts, and public data activation.

The old laptop should not connect directly to production PostgreSQL or Redis. It should run a same-repo scraper-agent command/container that polls the VPS for due jobs, leases one job, runs the grocer adapter locally, and uploads signed results back to the VPS.

## Scraper Business Logic

The scraper system should make it easy to add or update grocer integrations because grocer websites/APIs can change.

REMA 1000 discovery/refactor notes:

- `https://shop.rema1000.dk/avisvarer` is backed by Algolia product search plus REMA product detail APIs.
- Algolia `labels:avisvare` is good for discovering individual advertised products and avoids grouped Tjek offers such as one flyer offer representing several real products.
- REMA product detail/batch responses include advertised price validity in `prices[].starting_at` and `prices[].ending_at`.
- Tjek/Squid catalogs are still useful for grouping product-level offers into source papers/catalogs by date overlap.
- For REMA MVP publishing, only group into Tjek catalogs whose label contains `Uge`. Exclude long-running catalogs such as `Nu endnu lavere priser`, because those represent permanent/new normal prices rather than weekly grocery offer papers.
- The REMA detail API is sensitive to many requests in a row and can return 500s when hit aggressively. Limited batching/concurrency may be acceptable, but sustained bursts are the issue. Detail fetching must be slow/polite with delays between request groups, jitter, retry/backoff, and bounded chunks. It is acceptable for a full REMA detail crawl to take a long time.
- Live experiments on 2026-05-30: a 50-product batch partially failed with internal 500s; immediate follow-up batches of 40/30/20/10 all failed internally; after a 30s cooldown, 20-product and 10-product batches succeeded with 5s spacing, then later smaller batches failed again once the rate gate appeared triggered. Default REMA detail strategy should start at 20 products per batch, wait at least 10s plus jitter between batches, and if any batch returns internal 500s, stop the run or cool down for several minutes before retrying instead of continuing to hammer smaller batches.
- Follow-up experiment on 2026-05-30: individual `GET /api/v3/products/{id}` detail requests with a 1s delay succeeded for 20/20 products even while batch requests were still failing. Prefer individual delayed product detail requests for REMA enrichment. Use at least 1s delay plus jitter between products and fail the import if detail coverage drops below 95%.

There are currently about 8 confirmed grocers with available JSON APIs. V2 should support those, but implementation should be staged:

- Build one reference adapter first
- Add two more to prove the abstraction
- Add the remaining adapters after the contract stabilizes

Each grocer should be implemented behind a shared adapter contract. A likely shape:

```php
interface GrocerScraper
{
    public function grocerKey(): string;

    public function fetchPaper(): RawPaperPayload;

    public function parse(RawPaperPayload $payload): ParsedPaper;
}
```

Keep these concerns separate:

- Fetch raw JSON
- Parse grocer-specific structure
- Normalize offers
- Match products
- Validate import quality
- Persist import batch
- Activate public data
- Invalidate affected caches
- Alert on anomalies

Adapters should not write final public records directly. They should return structured parsed data. The central import pipeline decides what is valid and what becomes public.

## Scheduler And Reliability

The scheduler should be data-driven, not just cron-driven.

Cron should wake the system. The database should decide what is due.

For every grocer/import, track:

- `active_from`
- `active_until`
- `next_expected_import_at`
- `last_success_at`
- `last_failure_at`
- `health_status`

Freshness rule:

- Each grocer should have at least one successful import per offer period.
- Daily verification jobs should check that active data exists and is not stale.
- Alert if a grocer is stale beyond roughly 24 hours or if current offer period data is missing.

If a grocer import fails and old offers expire, do not silently extend expired offers. Hide expired offers from active public listings and mark the grocer as stale/unavailable internally.

## Alerting Rules

Email alerts are acceptable for MVP.

Alert on:

- Missed expected import window
- Repeated fetch failures
- Parse failures
- Suspiciously low offer count
- High normalization failure rate
- No active offers for a grocer that should have active offers
- Missing scraper-agent heartbeat

Avoid noisy success emails. Reliability alerts should be actionable.

## Import And Data Model Direction

Prefer immutable import batches over hard replacement.

A new scrape should create an import batch/paper record. Public queries should expose active offers based on validity dates. Admin/debug views should be able to inspect old batches and failures.

Important entities/tables expected later:

- `grocers`
- `scraper_agents`
- `scrape_jobs`
- `import_batches`
- `papers`
- `scraped_offers`
- `canonical_products`
- `product_matches`
- `price_observations`
- `normalization_failures`
- `import_alerts`

Public active offer rule:

```text
paper.active_from <= now <= paper.active_until
```

Expired offers may remain in the database for history/debug/SEO, but should not appear as active deals.

## Offer Publishing Rules

Publish an offer if the offer price and required display fields are valid, even if unit normalization fails.

If normalization fails:

- Show the deal price
- Mark unit normalization as unknown/failed
- Exclude the offer from unit-price sorting/comparison
- Count the failure in import quality metrics

Do not fabricate unit prices when parsing confidence is low.

## Unit Normalization

Normalize only when reliable.

Target normalized fields:

- Offer price
- Package amount
- Package unit
- Compare unit, e.g. `kg`, `ltr`, `stk`
- Unit price
- Confidence/status
- Failure reason

Relevant v1 idea to preserve: parse amount/unit from offer text or JSON, map units to compare units, and compute unit price from offer price divided by normalized amount.

Examples:

- grams and kilograms normalize to `kg`
- milliliters, centiliters, and liters normalize to `l`
- pieces, packs, trays, sets, and pairs normalize to `stk`

Normalization decisions from project planning:

- Implement normalization as pure PHP DTOs/value objects/services before persistence or real adapters.
- Parser output should use immutable readonly DTOs, not arrays or Eloquent models.
- DTO constructors should enforce required structural fields. Optional/derived fields should return explicit normalization results.
- Use PHP enums for normalized units and stable failure codes.
- Use source-unit alias maps for variants such as `GR.`, `gr`, `gram`, `KG.`, `kilo`, `ML.`, `CL.`, `LTR.`, `liter`, `litr`, `STK.`, `styk`, `PK.`, `pakke`, `BK.`, `bakke`, `sæt`, and `par`.
- Use `Brick\Math\BigDecimal` for money and unit-price math. Do not use PHP floats for business calculations.
- Store decimal values into database decimal columns after normalizing/calculating with BigDecimal.
- Accept Danish price formats such as `12,95`, `12.95`, `12`, `12,-`, and `12.`.
- Offer price must be present, concrete, and positive. Missing, zero, or negative prices are invalid for public publishing.
- Paper dates live on `papers`; offers inherit paper validity unless a later source proves per-offer validity is needed.
- The MVP offer model assumes one price maps to one purchasable package. Do not model multi-buy fields now.
- Conditional prices such as member-only, app-only, or loyalty/coupon prices should be excluded from MVP public publishing unless the same price is generally available.
- Quantity limits such as `max 6 per customer` do not block publishing; preserve them as metadata for future display.
- Ignore pant/deposit handling for MVP. Do not parse or subtract pant from prices.
- If a grocer provides unit price, store it. If package amount/unit are available, calculate our own unit price and compare.
- Unit-price mismatch tolerance is `0.05 DKK` after rounding to 2 decimals.
- If source unit price and calculated unit price disagree beyond tolerance, keep the offer displayable but add a normalization warning and exclude it from confident unit-price comparison.
- Package-like units such as `pk`, `pakke`, `bakke`, `sæt`, and `par` normalize to `stk`.
- If a package-like unit has no explicit count, assume count `1` for amount/unit-price purposes, but use a lower confidence score than an explicit count.
- If package amount/unit cannot be parsed but title and price are valid, partial-publish the offer without unit-price comparison and surface the failure in admin later.
- If one offer covers a package amount range such as `500-750 g`, publish the offer price but do not calculate unit price unless the source provides a trusted unit price.
- If one source offer represents multiple variants, the adapter decides whether to emit one offer or several. The shared contract accepts one normalized offer at a time.
- Shared title cleanup should be minimal: trim and collapse whitespace only. Source-specific cleanup belongs in adapters.
- `normalization_confidence` is a 0-100 integer. Use it to distinguish exact parses from assumption-based parses.
- Stable machine-readable failure codes are required for metrics/admin/tests. Initial examples: `price_missing`, `price_invalid`, `unit_unknown`, `amount_range`, `unit_price_mismatch`, and `conditional_offer`.

## Product Matching

Use strict, confidence-based matching. Avoid misleading price history.

Prefer matching by:

- EAN/barcode when available
- Grocer product ID scoped to grocer
- Normalized product name
- Brand
- Package amount
- Unit
- Category if available

Separate scraped offers from canonical products. Link to canonical products only when confidence is high enough.

Unmatched offers can still be displayed publicly, but they should not claim global price history or cross-grocer comparisons.

## Public UX

The website is not primarily a digital flyer viewer. Weekly papers are the data source, not the main UX model.

Optimize first for:

- Fast search
- Category browsing
- Grocer filtering
- Active deals
- Unit-price sorting when reliable
- Product detail/history as a second click

## API Direction

Treat future mobile app needs as a first-class read API concern, but do not overbuild.

Add versioned read-only API endpoints over time for:

- Active offers
- Grocers
- Search
- Product details
- Price history

Keep admin writes internal for MVP.

## Caching

Use import-based cache invalidation rather than only TTLs.

Cache likely public paths:

- Homepage active deals
- Search results
- Grocer pages
- Category pages
- Product detail pages
- Safe API responses

Invalidate affected cache entries when a new import batch changes active public data.

Target production cache is Redis. Local fallback can use database cache.

## Admin/Ops Surface

MVP should include a minimal protected admin/ops surface. It can be visually simple, but it must answer operational questions.

It should eventually show:

- Grocers
- Latest imports
- Failed imports
- Stale grocers
- Offer counts per import
- Normalization failure rates
- Scraper agent heartbeat
- Retry controls for a single grocer/job
- Raw and normalized payload inspection where practical

Email alerts without an admin view will become painful once grocers change APIs.

## Testing Strategy

Use committed JSON fixtures for scraper adapters.

Each adapter should have tests for:

- Extracting paper dates
- Extracting expected offer count from fixture
- Parsing known tricky prices
- Normalizing known tricky package units
- Failing clearly when required fields disappear

Avoid live HTTP tests as the primary test suite. Live checks may exist separately, but fixture tests should cover parser behavior deterministically.

## Deployment Direction

Use single-node Docker Compose on the cheap VPS unless there is a real multi-node need later.

Do not use Docker Swarm for MVP. It adds overhead without meaningful benefit for a single VPS.

Target production services:

- App
- Web server, likely Nginx or Caddy
- Queue worker
- Scheduler
- PostgreSQL
- Redis
- Optional Horizon

GitHub Actions target flow:

- Run tests/static analysis
- Build Docker image
- Push image to GHCR
- SSH into VPS
- Pull image
- Run migrations carefully
- Restart services
- Keep persistent volumes for PostgreSQL/Redis
- Back up PostgreSQL regularly

## Current Frontend State

The frontend is Inertia + Vue 3. The root page is currently:

```text
resources/js/Pages/Home.vue
```

The Inertia root Blade view is:

```text
resources/views/app.blade.php
```

The root route renders:

```php
Inertia::render('Home')
```

## Engineering Preferences

- Prefer small, correct changes.
- Keep architecture simple until complexity is justified.
- Do not add backward compatibility for v1 unless there is a concrete need.
- Do not split into services early.
- Preserve user trust over showing questionable data.
- Avoid loose product matching that creates misleading price history.
- Prefer explicit failure states over silent fallbacks.
- Prefer testable parser/normalization code over clever one-off scraping logic.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/vue3 (INERTIA_VUE) - v3
- vue (VUE) - v3
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `./bin/composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `./bin/php artisan route:list`). Use `./bin/php artisan list` to discover available commands and `./bin/php artisan [command] --help` to check parameters.
- Inspect routes with `./bin/php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `./bin/php artisan config:show app.name`, `./bin/php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `./bin/php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `./bin/php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/Pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `./bin/php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `./bin/php artisan list` and check their parameters with `./bin/php artisan [command] --help`.
- If you're creating a generic PHP class, use `./bin/php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `./bin/php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `./bin/php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `./bin/composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `./bin/php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `./bin/php artisan test --compact`.
- To run all tests in a file: `./bin/php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `./bin/php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== inertia-vue/core rules ===

# Inertia + Vue

Vue components must have a single root element.
- IMPORTANT: Activate `inertia-vue-development` when working with Inertia Vue client-side patterns.

</laravel-boost-guidelines>
