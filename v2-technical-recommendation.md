# Tilbudsfinder V2 Technical Recommendation

## Conclusion

Build v2 as a Laravel-based product rewrite, not a patched continuation of v1.

The old system had the right product insight: scrape weekly grocery offers, normalize prices/units, track validity dates, and let users search active deals. The rewrite should preserve that domain logic, but replace the brittle scraper/runtime architecture with a reliable ingestion pipeline, strong observability, cached public read paths, and a minimal admin surface.

## Recommended Tech Stack

- Backend/web: Laravel 11/12
- Frontend: Laravel Blade + Livewire or small Alpine.js interactions
- Database: PostgreSQL
- Cache/queues/locks: Redis
- Search MVP: PostgreSQL full-text + `pg_trgm`
- Queue monitoring: Laravel Horizon
- Scheduler: Laravel scheduler on VPS
- Scraper execution: same repo, dedicated `scraper-agent` command/container on old laptop
- Admin: minimal Laravel admin pages, optionally Filament if you want faster CRUD/admin tooling
- API: versioned read-only JSON API from day one, e.g. `/api/v1/offers`, `/api/v1/products`, `/api/v1/grocers`
- Deployment: Docker Compose + GitHub Actions + GHCR
- Hosting: cheap VPS for app/db/cache, old laptop as pull-based scraper agent
- Alerts: Laravel notifications via email initially
- Tests: Pest or PHPUnit with committed JSON fixtures per grocer

## Core Architecture

Use a Laravel monolith, but with clean internal boundaries:

- Public website
- Read-only public API
- Admin/ops dashboard
- Import scheduler
- Scraper job API
- Scraper agent
- Normalization/matching pipeline
- Cache invalidation layer

The VPS should be the source of truth. The laptop should not connect directly to production DB or Redis. It should poll the Laravel app for due scrape jobs, lease one job, run the grocer adapter locally, then upload signed results back to the VPS.

## Scraper Design

Each grocer gets an adapter behind a shared contract:

```php
interface GrocerScraper
{
    public function grocerKey(): string;

    public function fetchPaper(): RawPaperPayload;

    public function parse(RawPaperPayload $payload): ParsedPaper;
}
```

The pipeline should separate:

- Fetching raw JSON
- Parsing grocer-specific structure
- Normalizing offers
- Matching products
- Validating import quality
- Persisting import batch
- Activating public data
- Invalidating cache
- Alerting on anomalies

Do not let adapters directly write final public records. They should return structured parsed data, then the central pipeline decides what is valid.

## Data Model Direction

You want immutable-ish import history plus active public queries.

Important tables/entities:

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

Public pages should show only active offers where `paper.active_from <= now <= paper.active_until`.

Expired offers should remain addressable for SEO/debug/history, but not linked as active deals.

## Product Matching

Use strict matching.

MVP matching should prefer:

- EAN/barcode if available
- Grocer product ID scoped to grocer
- Normalized name
- Brand
- Package amount
- Unit
- Category if available

Do not show global price history unless confidence is high. Unmatched offers can still be displayed, but without strong historical claims.

## Normalization

Preserve the v1 idea, but make it explicit and testable.

Normalize:

- Offer price
- Package amount
- Package unit
- Compare unit, e.g. `kg`, `ltr`, `stk`
- Unit price
- Confidence/status
- Failure reason

If price is valid but unit parsing fails, publish the offer without unit-price comparison. Count those failures per import so you notice when a grocer changes format.

## Reliability

The scheduler should be data-driven, not just cron-driven.

After every successful paper import, store:

- `active_from`
- `active_until`
- `next_expected_import_at`
- `last_success_at`
- `last_failure_at`
- `health_status`

Cron wakes the system. The database decides what is due.

Alert on:

- Missed expected import window
- Repeated fetch failures
- Parse failures
- Suspiciously low offer count
- High normalization failure rate
- No active offers for a grocer that should have active offers
- Missing scraper-agent heartbeat

## Caching

Use import-based invalidation.

Cache:

- Homepage active deals
- Search results
- Grocer pages
- Category pages
- Product/detail pages
- API responses where safe

Invalidate affected caches when a new import batch changes public active data.

## CI/CD

Use Docker Compose and GitHub Actions.

Flow:

- Run tests/static analysis
- Build Docker image
- Push image to GHCR
- SSH into VPS
- Pull image
- Run migrations
- Restart app/worker/scheduler containers
- Keep Postgres/Redis persistent volumes
- Back up Postgres regularly

I would not use Docker Swarm for this unless you add multiple nodes. Single-node Compose is simpler and better aligned with a cheap VPS.

## MVP Scope

MVP should include:

- 8 grocer adapters using known JSON APIs
- Active deal search/browse
- Product detail with price history when confidently matched
- Unit-price normalization
- Read-only API for future app
- Minimal admin dashboard
- Scraper agent polling system
- Email alerts
- Fixture-based adapter tests
- Cached public pages

Defer:

- User accounts
- Saved products
- Personalized price alerts
- Full historical dataset browsing
- Meilisearch
- Native mobile app
- Manual product matching UI, unless matching becomes a blocker

## Strong Recommendation

Do not over-engineer the frontend or split into services early. Your real hard problems are ingestion reliability, data normalization, product identity, and operational visibility.

A Laravel monolith with a pull-based scraper agent gives you the best balance: fast to build, cheap to host, reliable enough for production, and still ready for a future mobile app through versioned read APIs.
