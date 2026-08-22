# Tilbudsfinder Admin Panel Handoff

Date: 2026-08-22  
Repository: `Tilbudsfinder`  
Branch observed during planning: `staging`  
Requested entry point: `/admin`

## Purpose of this document

This document transfers the complete admin-panel planning conversation to another Codex desktop task. It contains:

- The original request and project context
- What was discovered in the current codebase
- Every structured grilling question and the user's answer
- The security, correctness, and maintainability reviews
- The final agreed behavior
- Remaining architectural blockers
- A recommended implementation sequence
- A prompt that can be pasted into a new Codex task

No admin-panel code was implemented during the planning conversation.

There were unrelated existing working-tree changes in analytics/cookie-consent files. They were intentionally excluded from this work and must be preserved.

## Original request

The user wants a complete owner-operated administration panel available at `/admin`. It should provide a comprehensive view of Tilbudsfinder's operations and data, including grocers, scraper agents, scrape jobs, imports, papers, offers, normalization failures, product matching, search, popularity, audit data, queue monitoring, and system health.

The user explicitly requested that the functionality be discovered collaboratively through repeated `grill-me`, `thermo-nuclear-review`, and `thermos` passes before implementation.

## Codebase discoveries

### Existing stack

- Laravel 13
- PHP 8.5
- Inertia.js 3
- Vue 3
- Tailwind CSS 4
- PostgreSQL
- Redis queue/cache target
- Meilisearch integration with PostgreSQL fallback
- PHPUnit 12

### Existing public routes

There is currently no `/admin` route and no browser authentication UI.

Existing web routes cover:

- Homepage
- Store directory
- Offer search
- Offer detail
- Offer view tracking

### Authentication state

- The standard `users`, `password_reset_tokens`, and `sessions` tables exist.
- `User` is an `Authenticatable` model.
- There are no login/logout routes or controllers.
- There is no admin flag, role, gate, policy, or admin middleware.
- The development seeder creates `test@example.com` with password `password`.
- That seeded account must never receive admin access by default.

### Existing operational domain

The repository already contains models and persistence for:

- Grocers
- Scraper agents
- Scrape jobs
- Import batches
- Papers
- Scraped offers
- Normalization failures
- Grocer products
- Canonical products
- Product identifiers
- Product matches
- Price observations
- Search documents
- Popularity events and scores
- Laravel queue and failed-job tables

### Existing operational behavior

- A scheduler creates daily grocer scrape jobs after 02:00 Copenhagen time.
- A unique constraint currently permits one job per grocer and `scrape_date`.
- Scraper agents poll the VPS, claim jobs, scrape locally, and upload payloads.
- Claims, completion, failure, retries, lease expiry, and grocer-health changes currently live across `ScrapeJobScheduler`, `ScrapeJobWorker`, `ScraperAgentController`, and `ImportPersistencePipeline`.
- `max_attempts` is stored but not currently enforced by retry calculation.
- Retries currently stop at Copenhagen midnight rather than solely by attempt count.
- Disabled scraper-agent status is not checked during bearer-token authentication.
- Heartbeat currently writes the agent status back to `Active`.
- Matching jobs use a 120-second timeout while the default queue `retry_after` is 90 seconds.
- Production Docker listens to `matching,default`; local Docker currently listens only to the default queue.
- Horizon is a project target but is not installed.

### Existing public-read behavior

Public offer values are read through multiple independent paths:

- `scraped_offers` for homepage and detail behavior
- `offer_search_documents` for database search
- Meilisearch as another denormalized copy
- Popularity services using `publiclyActive()`
- Price history through price observations

This means future overrides or visibility controls require carefully defined cross-surface behavior.

### Environment caveat

Laravel Boost was connected to an unrelated MySQL database named `beboerapp`. Repository models and migrations were therefore treated as authoritative. The Boost database connection should be corrected before implementation relies on live database inspection.

## Complete grilling transcript

The following records every structured question and answer from the planning process.

### Round 1: Overall admin direction

**Question: What should `/admin` primarily be in its first complete release?**  
Answer: Operations cockpit.

**Question: Who needs to access the admin panel during the next year?**  
Answer: Only me.

**Question: How much operational control should the first version expose?**  
Answer: Safe controls only.

### Round 2: Dashboard, corrections, and authentication

**Question: When you open `/admin`, what should the dashboard answer first?**  
Answer: A mix of what is broken, what is happening, and how the site is doing.

**Question: Should the first admin release let you manually correct product and offer data?**  
Answer: Offers and matching.

**Question: How should the single owner account be created and recovered?**  
Answer: CLI-created account.

### Round 3: Navigation and initial controls

**Question: How broad should the first complete admin navigation be?**  
Answer: All domain areas.

**Question: Which bounded action set belongs in the first release?**  
Answer: Full safe toolkit.

**Question: Which offer fields may an admin override for public display?**  
Answer: Display fields only.

### Round 4: Matching, alerts, and deletion

**Question: How capable should manual product matching be?**  
Answer: Full review queue.

**Question: How should operational alerts work?**  
Answer: Dashboard alerts only.

**Question: What should happen when data needs to be removed from public view?**  
Answer: Hide or archive only.

### Round 5: Queue monitoring and presentation

**Question: How should queue and failed-job monitoring fit into `/admin`?**  
Answer: Install Horizon.

**Question: Which language should the admin interface use?**  
Answer: English.

**Question: How important is full mobile use of the admin panel?**  
Answer: Desktop-first responsive.

### Round 6: Security, audit, and insights

**Question: What security level should protect this internet-facing owner panel?**  
Answer: Strong password only.

**Question: How much mutation history should the admin retain?**  
Answer: Permanent structured audit.

**Question: What should the site-performance part of the dashboard include?**  
Answer: Popularity and search analytics.

### Round 7: Scraping, override lifetime, and raw data

**Question: When you click Run now for a grocer, where should the scrape execute?**  
Answer: Queue for the laptop agent.

**Question: How should corrections carry forward when a grocer imports the same product in a future paper?**  
Answer: Layered overrides.

**Question: How much raw diagnostic data should the admin expose?**  
Answer: Redacted viewer and download.

### Round 8: Account UI, dashboard layout, and delivery

**Question: What owner-account functionality belongs inside `/admin`?**  
Answer: Nothing.

**Question: How should the mixed dashboard be organized?**  
Answer: Fixed comprehensive layout.

**Question: How should this complete admin panel be delivered?**  
Answer: Phased vertical slices.

### Round 9: Price corrections, grocer disabling, and manual jobs

**Question: If you manually correct an imported offer price, what should product history show?**  
Answer: The user clarified that it should override from the edit rather than create a separate dual-track display.

**Question: What should disable grocer actually mean?**  
Answer: Separate switches.

**Question: How should Run now behave when today already has a scheduled scrape job?**  
Answer: Separate manual run.

### Round 10: Price timing, running cancellation, and match inheritance

**Question: Is the corrected price effective only from edit time, for the whole offer period, or display-only?**  
Answer: Replace the whole offer period.

**Question: What should happen if you try to cancel a scrape job already running on the laptop?**  
Answer: Request cancellation.

**Question: Should a manual match decision automatically apply to future offers for the same product?**  
Answer: Stable identifiers only.

### Round 11: Agent controls, index synchronization, and audit payloads

**Question: Should pausing an agent and revoking its credentials be separate actions?**  
Answer: Separate pause and revoke.

**Question: How quickly must corrections appear in Meilisearch-backed results?**  
Answer: Bounded eventual synchronization.

**Question: Should permanent audits store only explicitly approved changed fields?**  
Answer: Allowlisted deltas only.

### Round 12: First release and information architecture

**Question: What must the first production-usable `/admin` slice contain?**  
Answer: Observe plus recover.

**Question: Should canonical-product merging remain outside the initial matching release?**  
Answer: Defer merges.

**Question: Should navigation expose every domain but group it by workflow?**  
Answer: Four grouped sections.

### Round 13: Sessions and search privacy

**Question: How strict should admin sessions be?**  
Answer: Browser-session login.

**Question: When may the application persist a normalized search term for analytics?**  
Answer: Only with analytics consent.

**Question: How long should normalized search terms remain identifiable?**  
Answer: 90 days, then aggregate.

### Round 14: Reconfirmation, hidden offers, and maintenance operations

**Question: Which confirmation window should sensitive actions use?**  
Answer: 15-minute confirmation.

**Question: What should public users see when opening the direct URL of a manually hidden offer?**  
Answer: Return 404.

**Question: How should rebuild, synchronization, and rematching operations run?**  
Answer: The user expressed concern that a durable generic operation system was too complex.

### Round 15: Complexity reduction

**Question: Where should long-running maintenance actions live initially?**  
Answer: Simple queued jobs.

**Question: Which mutations should `/admin` support on day one?**  
Answer: Scraper recovery only.

**Question: What should implementation optimize for?**  
Answer: Future admin platform.

### Round 16: Defining future-platform investment

**Question: What does future admin platform mean?**  
Answer: Stable primitives, not a generic workflow engine.

**Question: How much history should simple queued maintenance buttons show?**  
Answer: Minimal run records.

**Question: How should the first dashboard obtain system health?**  
Answer: Database facts plus cached probes.

### Round 17: Running cancellation and public behavior

**Question: Should cooperative cancellation of a running laptop scrape be in the first release?**  
Answer: Defer it.

**Question: When a grocer's public-visibility switch is off, should all direct offer URLs become unavailable?**  
Answer: Hide listings only.

**Question: Which write capability should follow scraper recovery?**  
Answer: The user had no preference. The recommended choice was reversible offer visibility controls.

### Round 18: Pausing, revocation, and manual retries

**Question: If an agent is paused while it has a running job, what happens?**  
Answer: Finish the current job.

**Question: If credentials are revoked while the agent holds a lease, what happens?**  
Answer: Requeue immediately.

**Question: What retry policy should a manual scrape use?**  
Answer: Three attempts within six hours.

### Round 19: Run-now collisions and discoverability

**Question: What should Run now do when a grocer already has a pending or retrying job?**  
Answer: The user said to slot it in after the currently running job.

**Question: If today's pending scheduled job is cancelled, should that skip today's import?**  
Answer: Yes, skip today.

**Question: When a grocer is undiscoverable but direct offer URLs remain accessible, should those offers affect aggregates?**  
Answer: Exclude all aggregates.

### Round 20: Queueing behavior and heartbeat health

**Question: When a grocer has a running job, should Run now queue a follow-up?**  
Answer: Queue multiple.

**Question: When a grocer has a pending/retrying job, what should Run now do?**  
Answer: Accelerate it.

**Question: How long without a heartbeat makes an agent missing?**  
Answer: 15 minutes.

### Round 21: Manual queue limit and account recovery

**Question: How many manual runs may be waiting for one grocer?**  
Answer: Maximum five.

**Question: How long may an inactive admin session remain valid?**  
Answer: Two hours.

**Question: How should a forgotten owner password be recovered?**  
Answer: Hidden interactive CLI reset command.

### Round 22: Session enforcement and health probes

**Question: What should deployment do if production admin-session security is not configured?**  
Answer: Fail deployment checks.

**Question: How frequently should service health be probed?**  
Answer: Every request.

**Question: How should failed or stale probes appear?**  
Answer: Four explicit states.

### Round 23: Refining every-request health probes

**Question: Which requests should trigger probes?**  
Answer: Dashboard and System only.

**Question: Should probes block initial page rendering?**  
Answer: Load asynchronously.

**Question: After failure, should requests immediately probe again?**  
Answer: Thirty-second cooldown.

### Round 24: Queue priority, admin identity, and probe storage

**Question: Which job runs first after the current job?**  
Answer: Due scheduled job first.

**Question: How should the owner be distinguished from future public users?**  
Initial answer: Any authenticated user.

**Question: Where should latest health results be stored?**  
Initial answer: Cache only.

### Round 25: Security reconsideration

The risks of the two preceding answers were explained:

- Any authenticated user would make the seeded test account an administrator.
- Redis-backed cache cannot reliably preserve Redis's own failed-health state.

**Question: Should admin access use an explicit capability after considering the seeded account?**  
Final answer: Add explicit admin flag.

**Question: Where should Redis health remain visible during a Redis outage?**  
Final answer: One PostgreSQL snapshot per service.

## Final agreed first-release scope

### Authentication and authorization

- Private owner login
- Explicit admin flag/capability on `users`
- Admin gate applied to all `/admin` routes and Horizon
- Seeded test user is never an administrator
- Owner creation/promotion/reset through hidden interactive Artisan commands
- Passwords are never accepted as shell arguments
- No registration
- No account-settings UI
- Browser-close session expiration
- Two-hour idle timeout
- Fifteen-minute password reconfirmation for sensitive actions
- Login regenerates the session
- Logout invalidates the session and rotates the CSRF token
- Production deployment must fail if required secure session-cookie settings are absent

### Navigation

Group functionality into four sections:

- Operations
- Catalog
- Insights
- System

Do not create fifteen empty top-level destinations in the first slice. Only show functional pages.

### Dashboard

The fixed dashboard should combine:

1. Urgent health: stale grocers, failed imports/jobs, missing agents, expired or missing active data
2. Live operations: running jobs, recent imports, offer counts, normalization and matching coverage
3. Site performance: popularity and, later, consented search analytics

### Day-one reads

- Grocers
- Scraper agents
- Scrape jobs
- Import batches
- Papers and offers nested under import details where useful
- Normalization failures
- Redacted payload diagnostics
- Audit events
- Horizon monitoring
- System health

### Day-one writes

Limit the first release to scraper recovery:

- Run now
- Retry eligible jobs
- Cancel pending or retrying jobs
- Pause an agent
- Revoke agent credentials
- Separate grocer import and discoverability switches

Do not include running-job cancellation, offer-field corrections, manual matching, canonical merges, or generic maintenance workflows in the first release.

## Refined domain decisions

### Scraper-agent state

Authorization, credentials, and heartbeat health are independent concerns.

The minimum proposed durable state is:

- `accepting_jobs` boolean
- Nullable `token_hash`; null means no valid credential
- `last_heartbeat_at`
- Health derived from heartbeat age

Behavior:

- Pausing sets `accepting_jobs` false.
- A paused agent may finish and upload its current leased job.
- It may not claim another job.
- Revocation invalidates credentials.
- An agent is missing after 15 minutes without heartbeat.
- Heartbeat updates timestamps/version information but never changes administrative authorization.

### Scrape-job semantics

The simpler reviewed model should use:

- `source`: scheduled or manual
- Nullable `requested_by_user_id`
- `retry_until`
- Existing `attempt`, `max_attempts`, `status`, and `scheduled_for`

Retry is a lifecycle status, not a trigger/source type.

Manual retries use at most three attempts within six hours, independent of Copenhagen midnight.

Run-now behavior:

- A pending/retrying job is accelerated to now.
- If another job is running, manual work waits until it reaches a terminal state.
- Due scheduled work has priority over manual work.
- Cancelling today's scheduled job intentionally skips today's cycle and must display that warning.
- Running jobs are not cancellable in the first release.

The user initially selected up to five queued manual successors. The final Thermos maintainability review strongly recommends reducing this to one coalesced pending manual successor per grocer. This remains an explicit issue to resolve before implementation.

### Fenced leases

The final Thermos correctness review found this foundational requirement:

- Every claim receives a random lease token or generation.
- The token/generation is returned to the agent.
- Upload and failure requests must include it.
- Job and lease must be locked and rechecked before persistence and finalization.
- Stale lease writes return `409` or become safe no-ops.
- Payload receipt must be idempotent.

Reason: credential revocation can race an upload that already passed middleware. Without fencing, the old agent can complete while a second agent processes the requeued job.

Revocation cannot retroactively stop an HTTP request already executing. The implementation needs a linearization rule:

- If upload owns the locked transaction first, it completes and revocation observes the terminal job.
- If revocation owns the lock first, it invalidates the lease and the upload is rejected before persistence.

### Centralized job transitions

Scheduler, agent API, local worker, import pipeline, CLI, and admin must use the same explicit transactional actions.

Do not create a generic workflow engine. Use small use-case actions for claim, completion, failure, retry, cancellation, pause, revocation, and manual scheduling.

Claim behavior must:

- Lock the candidate grocer
- Recheck that no job for that grocer is executing
- Enforce scheduled-before-manual ordering
- Enforce attempts and retry deadline
- Validate the fenced lease during completion

Use a PostgreSQL database invariant, such as an appropriate partial unique constraint, to prevent more than one executing job per grocer.

### Grocer controls

Split the existing overloaded `is_enabled` concept into:

- `imports_enabled`
- `is_discoverable`

`imports_enabled` controls scheduling and manual execution eligibility.

`is_discoverable` controls public discovery and aggregation:

- Store directory
- Homepage
- Search
- Public API
- Recommendations
- Comparisons
- Popularity
- Public price history
- Cached rankings

When a grocer is not discoverable, direct offer URLs remain accessible by explicit user decision. Those direct pages must not reintroduce the grocer into related recommendations, comparisons, popularity, or history.

### Request-time discoverability enforcement

Search-index synchronization is not an access-control boundary.

- Database search must check the authoritative grocer flag.
- Meilisearch result hydration must check the authoritative grocer flag.
- The search-document builder must delete or refuse documents for nondiscoverable grocers.
- Cache and Meilisearch synchronization remains idempotent convergence work.
- A delayed or failed index update must not leak nondiscoverable offers into search.

### Two-phase grocer migration

Do not replace `is_enabled` in one deployment.

Recommended rollout:

1. Add `imports_enabled` and `is_discoverable`.
2. Backfill both from every row's existing `is_enabled` value.
3. Deploy code that reads and writes the new fields.
4. Keep the old column temporarily for deployment compatibility.
5. Remove `is_enabled` in a later deployment.

This avoids briefly reactivating disabled grocers through default-true columns.

### Auditing

Permanent append-only audit events should contain:

- Actor
- Action enum
- Target type and ID
- Reason when applicable
- Correlation ID
- Timestamp
- Allowlisted field-level before/after delta

Never store complete model snapshots, token material, raw payloads, token hashes, or large metadata blobs.

Password resets must invalidate existing sessions and password-confirmation state.

### Payload diagnostics

- Never serve `raw_payload_path` directly.
- Resolve only the expected configured storage disk/key.
- Enforce strict size limits.
- Recursively redact sensitive fields.
- Reject malformed or oversized payloads safely.
- Stream the sanitized response.
- Use attachment and `nosniff` headers.
- Require recent password confirmation for downloads.
- Audit the access without storing the payload body.

### Horizon and queues

- Install Horizon as part of the foundation.
- Configure supervisors for `default` and `matching`.
- Ensure `retry_after` is greater than job timeout.
- Correct local Docker so it consumes `matching` as well as `default`.
- Secure Horizon with the explicit admin capability.
- Prefer monitoring-only Horizon initially.
- If retry/forget/purge mutations are enabled, protect them with the same password confirmation and auditing as admin actions.

### Health monitoring

The grilling initially selected per-visit asynchronous probes with a 30-second failure cooldown and PostgreSQL snapshots.

The final Thermos maintainability review recommends a simpler model:

- Run one `system:check-health` command every minute.
- Protect it with `withoutOverlapping()`.
- Probe Redis, Meilisearch, and storage with strict timeouts.
- Upsert one latest sanitized PostgreSQL snapshot per service.
- Dashboard and System pages only read snapshots.
- Derive stale state from snapshot age.
- Add manual refresh later only if one-minute freshness proves insufficient.

This scheduled approach is the recommended final direction because it removes page-triggered side effects, multi-tab races, cooldown orchestration, and distributed locking complexity.

Health UI states:

- Healthy
- Unhealthy
- Stale
- Unknown

Show last attempt and last success independently. Never expose raw exceptions or credentials.

### Search analytics

Search analytics are not part of the first recovery slice.

Later behavior:

- Require analytics consent.
- Normalize and sanitize terms.
- Reject or suppress likely personal information.
- Keep identifiable normalized terms for 90 days.
- Retain aggregate trends for two years.
- Suppress rare terms in the admin UI.
- Never attach user identity or reconstruct visitor sessions.

### Offer corrections and matching

These are deferred until after recovery controls.

Recommended order:

1. Reversible individual offer hide/restore
2. Effective-offer projection and layered corrections
3. Manual match assign/reject/unassign
4. Advanced matching and canonical merging

Individual hidden offers return 404 on direct public URLs.

Corrections use precedence:

1. Offer-specific override
2. Reusable grocer-product correction
3. Immutable scraped source

A price correction is intended to replace the public value for the offer's complete validity period while preserving the original imported value as source evidence and in audit history.

Manual match decisions carry forward only through stable identifiers such as EAN or grocer product ID. Title-only decisions apply only to the current offer.

Canonical-product merging remains deferred until conflict-preview, observation-reconciliation, rollback, and identifier-transfer semantics are designed.

## Maintainability guardrails

- Use one invokable controller per page or mutation.
- Controllers authorize, validate, call a query/action, and return.
- Keep dashboard aggregation out of controllers.
- Use focused page query/data classes for nontrivial reads.
- Use one Vue page per functional resource.
- Compose pages from small domain widgets.
- Share stable primitives only: layout, pagination, status badges, confirmation dialogs, empty states, filters, tables, and timelines.
- Do not create a metadata-driven generic CRUD renderer.
- Do not create a generic state-machine/workflow engine.
- Do not create empty navigation destinations.
- Every mutation should be a small, named transactional use-case action.

## Recommended implementation sequence

### Phase 0: Correctness foundation

- Fix Boost connection before live database inspection.
- Add explicit admin capability and authentication foundation.
- Add secure session configuration and deployment checks.
- Install/configure Horizon and correct queue timing/topology.
- Introduce fenced scrape-job leases.
- Centralize job transitions behind explicit actions.
- Enforce one executing job per grocer.
- Separate agent authorization from heartbeat health.
- Add append-only allowlisted audit events.
- Perform the additive half of the grocer-state migration.

### Phase 1: Read-only operations

- Admin layout and grouped navigation
- Dashboard
- Grocer list/detail
- Agent list/detail
- Job list/detail
- Import list/detail
- Nested papers, offers, failures, and payload diagnostics
- Audit list/detail
- Horizon access
- Scheduled system health snapshots

### Phase 2: Scraper recovery writes

- Run now / accelerate eligible work
- Manual retry behavior
- Cancel pending/retrying jobs with skip-today warning
- Pause agent
- Revoke agent credential with fenced-lease handling
- Import-enable switch
- Discoverability switch with request-time enforcement and queued index convergence

### Phase 3: Visibility moderation

- Hide/restore individual offers
- Public 404 behavior
- Search/cache/index invalidation

### Phase 4: Corrections

- Effective-offer projection
- Layered text/image/price/unit overrides
- Full-period corrected-price semantics
- Cross-surface contract tests

### Phase 5: Matching

- Review queue
- Assign/reject/unassign
- Stable-identifier inheritance
- Price-observation and search-document reconciliation
- Defer canonical merges

### Phase 6: Insights and maintenance

- Consented search analytics
- Popularity dashboards
- Minimal maintenance-run records
- Safe unique queued rebuild/sync/rematch actions

## Required test categories

- Guest redirects and non-admin 403 responses
- Seeded user cannot access admin or Horizon
- Login session regeneration and logout invalidation
- Password reset invalidates existing sessions
- Sensitive actions require recent password confirmation
- Pause/claim race
- Revoke/upload race
- Stale lease rejection
- Duplicate payload idempotency
- Concurrent claims by multiple agents
- One executing job per grocer database invariant
- Scheduled-before-manual ordering
- Attempt and retry deadline enforcement
- Copenhagen midnight boundaries
- Cancel-today behavior
- Two-phase grocer flag migration
- Nondiscoverable grocer exclusion across homepage, directory, API, database search, Meilisearch hydration, recommendations, comparisons, popularity, and public history
- Direct offer URL remains accessible for nondiscoverable grocer
- Individually hidden offer returns 404
- Raw payload redaction, malformed payload, oversized payload, headers, and authorization
- Audit allowlisting and secret exclusion
- Horizon authorization
- Queue `retry_after` and timeout compatibility
- Health snapshot healthy/unhealthy/stale/unknown behavior
- Inertia page props, pagination, filters, empty states, and accessibility
- Frontend build and focused PHPUnit tests

## Remaining decision before implementation

The only material product/complexity disagreement left is manual follow-up depth:

- User-selected behavior: allow up to five queued manual successors per grocer.
- Final Thermos recommendation: coalesce repeated Run now requests into at most one pending manual successor.

Recommended resolution: use one coalesced successor. It satisfies recovery needs while avoiding a second per-grocer queueing subsystem.

## Ready-to-paste prompt for the other desktop

Copy the following prompt into a new Codex task opened on the Tilbudsfinder repository:

```text
Read admin-panel-handoff.md completely, then inspect the current repository and AGENTS.md. Treat the handoff as the agreed product and architecture context for the Tilbudsfinder /admin panel.

Before changing code, use the relevant Laravel, Inertia Vue, Tailwind, and TDD skills and search version-specific Laravel documentation through Boost. Preserve unrelated working-tree changes.

Begin with Phase 0 only. First report the exact files, migrations, actions, middleware, policies/gates, configuration, and tests you intend to add or modify. Resolve the remaining manual-job queue decision in favor of one coalesced pending manual successor unless I explicitly override it. Then implement Phase 0 through a test-first workflow, run focused PHPUnit tests, run Pint on modified PHP files, and verify the frontend build only if frontend files are changed.

Do not begin later admin UI phases until Phase 0 is verified.
```

## Current status

- Planning and reviews are complete.
- No admin implementation has started.
- The latest security/correctness review still treats fenced leases, centralized transitions, and request-time discoverability as non-negotiable foundations.
- The latest maintainability review recommends one coalesced pending manual successor and scheduled health snapshots.
- Existing unrelated analytics/cookie-consent changes must remain untouched.
