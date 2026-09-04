<?php

namespace App\Scrapers;

use App\Enums\GrocerHealthStatus;
use App\Enums\ScrapeJobStatus;
use App\Enums\ScraperAgentStatus;
use App\Models\ScrapeJob;
use App\Models\ScraperAgent;
use App\Scrapers\Exceptions\ScraperRunException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ScrapeJobWorker
{
    public const LEASE_MINUTES = 180;

    public function __construct(
        private readonly ScraperRunService $scraperRunService,
    ) {}

    public function work(string $agentSlug, ?callable $progress = null): ?ScrapeJob
    {
        $agent = $this->heartbeat($agentSlug);
        $job = $this->claimJob($agent);

        if (! $job) {
            $this->progress($progress, 'No scrape jobs are available.');

            return null;
        }

        $this->progress($progress, "Running scrape job {$job->id} for {$job->grocer->slug}...");

        try {
            $result = $this->scraperRunService->run(
                grocerKey: $job->grocer->slug,
                progress: $progress,
                scrapeJob: $job,
                skipKnown: true,
            );

            $status = $result->importedPaperCount > 0
                ? ScrapeJobStatus::Succeeded
                : ScrapeJobStatus::NoChanges;

            $completed = $this->markSuccessful($job, $status, [
                'fetched_paper_count' => $result->fetchedPaperCount,
                'imported_paper_count' => $result->importedPaperCount,
                'skipped_duplicate_count' => $result->skippedDuplicateCount,
            ], expectedAttempt: $job->attempt);

            if (! $completed) {
                $this->progress($progress, "Scrape job {$job->id} result was ignored because its lease is no longer current.");

                return $job->refresh();
            }

            $this->progress($progress, "Scrape job {$job->id} finished with status {$status->value}.");
        } catch (Throwable $exception) {
            $failed = $this->markFailedAttempt($job, $exception, expectedAttempt: $job->attempt);
            $message = $failed
                ? "Scrape job {$job->id} failed: {$exception->getMessage()}"
                : "Scrape job {$job->id} failure was ignored because its lease is no longer current.";
            $this->progress($progress, $message);
        }

        return $job->refresh();
    }

    public function heartbeat(string $agentSlug, ?string $appVersion = null): ScraperAgent
    {
        return ScraperAgent::updateOrCreate(
            ['slug' => $agentSlug],
            [
                'name' => str($agentSlug)->replace('-', ' ')->title()->toString(),
                'status' => ScraperAgentStatus::Active,
                'app_version' => $appVersion,
                'last_seen_at' => now(),
                'last_heartbeat_at' => now(),
            ],
        );
    }

    public function claimJob(ScraperAgent $agent): ?ScrapeJob
    {
        return DB::transaction(function () use ($agent): ?ScrapeJob {
            $job = ScrapeJob::query()
                ->with('grocer')
                ->whereIn('status', [ScrapeJobStatus::Pending, ScrapeJobStatus::Retrying])
                ->where('scheduled_for', '<=', now())
                ->orderBy('scheduled_for')
                ->lockForUpdate()
                ->first();

            if (! $job) {
                return null;
            }

            $context = $job->status === ScrapeJobStatus::Retrying
                ? Arr::except($job->context ?? [], [
                    'fetched_paper_count',
                    'imported_paper_count',
                    'skipped_duplicate_count',
                ])
                : $job->context;

            $job->update([
                'scraper_agent_id' => $agent->id,
                'status' => ScrapeJobStatus::Running,
                'attempt' => $job->attempt + 1,
                'leased_until' => now()->addMinutes(self::LEASE_MINUTES),
                'started_at' => now(),
                'finished_at' => null,
                'failure_reason' => null,
                'context' => $context,
            ]);

            return $job->refresh()->load('grocer');
        });
    }

    public function beginUpload(ScrapeJob $job, ScraperAgent $agent, int $attempt): ?ScrapeJob
    {
        return DB::transaction(function () use ($job, $agent, $attempt): ?ScrapeJob {
            $lockedJob = $this->lockJob($job);

            if (! $lockedJob->isActiveAttempt($agent->id, $attempt, ScrapeJobStatus::Running)) {
                return null;
            }

            $lockedJob->update([
                'status' => ScrapeJobStatus::Uploading,
                'leased_until' => now()->addMinutes(self::LEASE_MINUTES),
                'payload_received_at' => now(),
            ]);

            return $lockedJob->refresh()->load('grocer');
        });
    }

    /**
     * @param  array<string, int>  $context
     */
    public function markSuccessful(
        ScrapeJob $job,
        ScrapeJobStatus $status,
        array $context,
        ?int $expectedAttempt = null,
        ScrapeJobStatus $expectedStatus = ScrapeJobStatus::Running,
    ): bool {
        return DB::transaction(function () use ($job, $status, $context, $expectedAttempt, $expectedStatus): bool {
            $lockedJob = $this->lockJob($job);

            if ($expectedAttempt !== null && ! $lockedJob->isActiveAttempt(
                (string) $job->scraper_agent_id,
                $expectedAttempt,
                $expectedStatus,
            )) {
                return false;
            }

            $lockedJob->update([
                'status' => $status,
                'leased_until' => null,
                'finished_at' => now(),
                'payload_received_at' => now(),
                'failure_reason' => null,
                'context' => $context,
            ]);

            $lockedJob->grocer->update([
                'health_status' => GrocerHealthStatus::Healthy,
                'last_success_at' => now(),
            ]);

            return true;
        });
    }

    public function markFailedAttempt(
        ScrapeJob $job,
        Throwable $exception,
        ?int $expectedAttempt = null,
        ScrapeJobStatus $expectedStatus = ScrapeJobStatus::Running,
        ?string $expectedAgentId = null,
    ): bool {
        $context = $exception instanceof ScraperRunException && $exception->result !== null
            ? [
                'fetched_paper_count' => $exception->result->fetchedPaperCount,
                'imported_paper_count' => $exception->result->importedPaperCount,
                'skipped_duplicate_count' => $exception->result->skippedDuplicateCount,
            ]
            : [];

        return $this->markFailed(
            $job,
            $exception->getMessage(),
            $expectedAttempt,
            $expectedStatus,
            $expectedAgentId,
            $context,
        );
    }

    /**
     * @param  array<string, int>  $context
     */
    public function markFailed(
        ScrapeJob $job,
        string $failureReason,
        ?int $expectedAttempt = null,
        ScrapeJobStatus $expectedStatus = ScrapeJobStatus::Running,
        ?string $expectedAgentId = null,
        array $context = [],
    ): bool {
        return DB::transaction(function () use ($job, $failureReason, $expectedAttempt, $expectedStatus, $expectedAgentId, $context): bool {
            $lockedJob = $this->lockJob($job);

            if ($expectedAttempt !== null && ! $lockedJob->isActiveAttempt(
                $expectedAgentId ?? (string) $job->scraper_agent_id,
                $expectedAttempt,
                $expectedStatus,
            )) {
                return false;
            }

            $retryAt = $this->nextRetryAt($lockedJob);
            $hasRetryTimeRemaining = $retryAt !== null;

            $lockedJob->update([
                'status' => $hasRetryTimeRemaining ? ScrapeJobStatus::Retrying : ScrapeJobStatus::Failed,
                'scheduled_for' => $hasRetryTimeRemaining ? $retryAt : $lockedJob->scheduled_for,
                'leased_until' => null,
                'finished_at' => now(),
                'failure_reason' => $failureReason,
                'context' => [
                    ...($lockedJob->context ?? []),
                    ...$context,
                    'last_failed_attempt' => $lockedJob->attempt,
                ],
            ]);

            $lockedJob->grocer->update([
                'health_status' => $hasRetryTimeRemaining ? GrocerHealthStatus::Failing : GrocerHealthStatus::Stale,
                'last_failure_at' => now(),
            ]);

            return true;
        });
    }

    private function lockJob(ScrapeJob $job): ScrapeJob
    {
        return ScrapeJob::query()
            ->with('grocer')
            ->whereKey($job->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function retryDelayMinutes(int $attempt): int
    {
        return min(30 * (2 ** max(0, $attempt - 1)), 240);
    }

    private function nextRetryAt(ScrapeJob $job): ?CarbonImmutable
    {
        $next = now()->addMinutes($this->retryDelayMinutes($job->attempt))->toImmutable();
        $midnight = $this->scrapeDateMidnight($job);

        if ($next->greaterThanOrEqualTo($midnight)) {
            return null;
        }

        return $next;
    }

    private function scrapeDateMidnight(ScrapeJob $job): CarbonImmutable
    {
        $scrapeDate = $job->scrape_date?->timezone('Europe/Copenhagen')->toDateString()
            ?? now('Europe/Copenhagen')->toDateString();

        return Carbon::parse($scrapeDate, 'Europe/Copenhagen')
            ->addDay()
            ->utc()
            ->toImmutable();
    }

    private function progress(?callable $progress, string $message): void
    {
        if ($progress !== null) {
            $progress($message);
        }
    }
}
