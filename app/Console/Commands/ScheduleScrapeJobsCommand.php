<?php

namespace App\Console\Commands;

use App\Scrapers\ScrapeJobScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('scraper:schedule')]
#[Description('Create due scrape jobs for enabled grocers')]
class ScheduleScrapeJobsCommand extends Command
{
    public function handle(ScrapeJobScheduler $scheduler): int
    {
        $jobs = $scheduler->scheduleDueJobs();

        $this->info('Scheduled scrape jobs: '.count($jobs));

        foreach ($jobs as $job) {
            $this->line("- {$job->grocer->slug}: {$job->id}");
        }

        return self::SUCCESS;
    }
}
