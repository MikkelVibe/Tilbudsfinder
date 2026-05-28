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
