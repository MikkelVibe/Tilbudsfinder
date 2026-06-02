<?php

namespace App\Scrapers;

use App\Enums\GrocerHealthStatus;
use App\Enums\ScrapeJobStatus;
use App\Enums\ScraperAgentStatus;
use App\Models\ScrapeJob;
use App\Models\ScraperAgent;
use Carbon\CarbonImmutable;
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
                sleepBetweenDetailRequests: true,
                progress: $progress,
                scrapeJob: $job,
                skipKnown: true,
            );

            $status = $result->importedPaperCount > 0
                ? ScrapeJobStatus::Succeeded
                : ScrapeJobStatus::NoChanges;

            $this->markSuccessful($job, $status, [
                'fetched_paper_count' => $result->fetchedPaperCount,
                'imported_paper_count' => $result->importedPaperCount,
                'skipped_duplicate_count' => $result->skippedDuplicateCount,
            ]);

            $this->progress($progress, "Scrape job {$job->id} finished with status {$status->value}.");
        } catch (Throwable $exception) {
            $this->markFailedAttempt($job, $exception);
            $this->progress($progress, "Scrape job {$job->id} failed: {$exception->getMessage()}");
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

            $job->update([
                'scraper_agent_id' => $agent->id,
                'status' => ScrapeJobStatus::Running,
                'attempt' => $job->attempt + 1,
                'leased_until' => now()->addMinutes(self::LEASE_MINUTES),
                'started_at' => now(),
                'finished_at' => null,
                'failure_reason' => null,
            ]);

            return $job->refresh()->load('grocer');
        });
    }

    /**
     * @param  array<string, int>  $context
     */
    public function markSuccessful(ScrapeJob $job, ScrapeJobStatus $status, array $context): void
    {
        DB::transaction(function () use ($job, $status, $context): void {
            $job->update([
                'status' => $status,
                'leased_until' => null,
                'finished_at' => now(),
                'payload_received_at' => now(),
                'failure_reason' => null,
                'context' => $context,
            ]);

            $job->grocer->update([
                'health_status' => GrocerHealthStatus::Healthy,
                'last_success_at' => now(),
            ]);
        });
    }

    public function markFailedAttempt(ScrapeJob $job, Throwable $exception): void
    {
        $this->markFailed($job, $exception->getMessage());
    }

    public function markFailed(ScrapeJob $job, string $failureReason): void
    {
        DB::transaction(function () use ($job, $failureReason): void {
            $retryAt = $this->nextRetryAt($job);
            $hasRetryTimeRemaining = $retryAt !== null;

            $job->update([
                'status' => $hasRetryTimeRemaining ? ScrapeJobStatus::Retrying : ScrapeJobStatus::Failed,
                'scheduled_for' => $hasRetryTimeRemaining ? $retryAt : $job->scheduled_for,
                'leased_until' => null,
                'finished_at' => now(),
                'failure_reason' => $failureReason,
                'context' => [
                    ...($job->context ?? []),
                    'last_failed_attempt' => $job->attempt,
                ],
            ]);

            $job->grocer->update([
                'health_status' => $hasRetryTimeRemaining ? GrocerHealthStatus::Failing : GrocerHealthStatus::Stale,
                'last_failure_at' => now(),
            ]);
        });
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
