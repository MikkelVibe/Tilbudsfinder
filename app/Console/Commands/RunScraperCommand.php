<?php

namespace App\Console\Commands;

use App\Scrapers\Exceptions\ScraperRunException;
use App\Scrapers\ScraperRunService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('scraper:run {grocer : The grocer scraper key, e.g. rema1000}')]
#[Description('Fetch and import active papers for a grocer scraper')]
class RunScraperCommand extends Command
{
    public function handle(ScraperRunService $scraperRunService): int
    {
        try {
            $result = $scraperRunService->run(
                (string) $this->argument('grocer'),
            );
        } catch (ScraperRunException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Scraper run failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Scraper [{$result->grocerKey}] completed.");
        $this->line("Fetched papers: {$result->fetchedPaperCount}");
        $this->line("Imported papers: {$result->importedPaperCount}");
        $this->line("Skipped duplicates: {$result->skippedDuplicateCount}");
        $this->line("Failed papers: {$result->failedPaperCount}");

        foreach ($result->papers as $paper) {
            $message = $paper->message ? " ({$paper->message})" : '';

            $this->line("Paper {$paper->sourceExternalId}: {$paper->status}{$message}");
        }

        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
