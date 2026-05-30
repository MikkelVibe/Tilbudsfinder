<?php

namespace App\Console\Commands;

use App\Scrapers\ScrapeJobWorker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('scraper:work {--agent= : The single scraper agent slug, e.g. apartment-laptop}')]
#[Description('Claim and run one due scrape job')]
class WorkScrapeJobCommand extends Command
{
    public function handle(ScrapeJobWorker $worker): int
    {
        $agentSlug = (string) $this->option('agent');

        if ($agentSlug === '') {
            $this->error('The --agent option is required.');

            return self::FAILURE;
        }

        $job = $worker->work($agentSlug, function (string $message): void {
            $this->line($message);
        });

        if (! $job) {
            return self::SUCCESS;
        }

        return $job->status->value === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
