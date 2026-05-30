<?php

namespace App\Scrapers;

use App\Enums\ScrapeJobStatus;
use App\Models\Grocer;
use App\Models\ScrapeJob;
use Illuminate\Support\Facades\DB;

class ScrapeJobScheduler
{
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

            $scheduledJobs = [];

            Grocer::query()
                ->where('is_enabled', true)
                ->whereNotNull('next_expected_import_at')
                ->where('next_expected_import_at', '<=', now())
                ->orderBy('next_expected_import_at')
                ->lockForUpdate()
                ->each(function (Grocer $grocer) use (&$scheduledJobs): void {
                    if ($this->hasOpenJob($grocer)) {
                        return;
                    }

                    $scheduledJobs[] = ScrapeJob::create([
                        'grocer_id' => $grocer->id,
                        'scraper_agent_id' => null,
                        'status' => ScrapeJobStatus::Pending,
                        'attempt' => 0,
                        'max_attempts' => 3,
                        'scheduled_for' => now(),
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

    private function hasOpenJob(Grocer $grocer): bool
    {
        return $grocer->scrapeJobs()
            ->whereIn('status', [
                ScrapeJobStatus::Pending,
                ScrapeJobStatus::Leased,
                ScrapeJobStatus::Running,
                ScrapeJobStatus::Uploading,
                ScrapeJobStatus::Retrying,
            ])
            ->exists();
    }
}
