<?php

namespace App\Scrapers;

use App\Enums\GrocerHealthStatus;
use App\Enums\ScrapeJobStatus;
use App\Models\Grocer;
use App\Models\ScrapeJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ScrapeJobScheduler
{
    private const SCHEDULE_HOUR = 2;

    public function __construct(
        private readonly ScrapeJobWorker $worker,
    ) {}

    /**
     * @return list<ScrapeJob>
     */
    public function scheduleDueJobs(): array
    {
        return DB::transaction(function (): array {
            $this->recoverExpiredLeases();
            $this->failExpiredDailyCycles();

            $now = now('Europe/Copenhagen')->toImmutable();

            if ($now->hour < self::SCHEDULE_HOUR) {
                return [];
            }

            $scheduledJobs = [];
            $scrapeDate = $now->toDateString();
            $scheduledFor = $now->setTime(self::SCHEDULE_HOUR, 0)->utc();

            Grocer::query()
                ->where('is_enabled', true)
                ->orderBy('slug')
                ->lockForUpdate()
                ->each(function (Grocer $grocer) use (&$scheduledJobs, $scrapeDate, $scheduledFor): void {
                    if ($this->hasDailyCycle($grocer, $scrapeDate)) {
                        return;
                    }

                    $scheduledJobs[] = ScrapeJob::create([
                        'grocer_id' => $grocer->id,
                        'scraper_agent_id' => null,
                        'scrape_date' => $scrapeDate,
                        'status' => ScrapeJobStatus::Pending,
                        'attempt' => 0,
                        'max_attempts' => 3,
                        'scheduled_for' => $scheduledFor,
                    ]);
                });

            return $scheduledJobs;
        });
    }

    private function recoverExpiredLeases(): void
    {
        ScrapeJob::query()
            ->whereIn('status', [ScrapeJobStatus::Leased, ScrapeJobStatus::Running, ScrapeJobStatus::Uploading])
            ->whereNotNull('leased_until')
            ->where('leased_until', '<', now())
            ->lockForUpdate()
            ->get()
            ->each(function (ScrapeJob $job): void {
                $this->worker->markFailed($job, 'Scrape job lease expired before the agent reported completion.');
            });
    }

    private function failExpiredDailyCycles(): void
    {
        $today = now('Europe/Copenhagen')->toDateString();

        ScrapeJob::query()
            ->with('grocer')
            ->whereIn('status', [
                ScrapeJobStatus::Pending,
                ScrapeJobStatus::Leased,
                ScrapeJobStatus::Running,
                ScrapeJobStatus::Uploading,
                ScrapeJobStatus::Retrying,
            ])
            ->whereNotNull('scrape_date')
            ->where('scrape_date', '<', $today)
            ->lockForUpdate()
            ->get()
            ->each(function (ScrapeJob $job): void {
                $job->update([
                    'status' => ScrapeJobStatus::Failed,
                    'leased_until' => null,
                    'finished_at' => now(),
                    'failure_reason' => 'Daily scrape cycle expired at Copenhagen midnight before a successful run.',
                    'context' => [
                        ...($job->context ?? []),
                        'expired_at_copenhagen_midnight' => CarbonImmutable::now('Europe/Copenhagen')->startOfDay()->toIso8601String(),
                    ],
                ]);

                $job->grocer->update([
                    'health_status' => GrocerHealthStatus::Stale,
                    'last_failure_at' => now(),
                ]);
            });
    }

    private function hasDailyCycle(Grocer $grocer, string $scrapeDate): bool
    {
        return $grocer->scrapeJobs()
            ->whereDate('scrape_date', $scrapeDate)
            ->exists();
    }
}
