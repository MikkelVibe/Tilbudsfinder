<?php

namespace App\Console\Commands;

use App\Scrapers\Exceptions\ScraperRunException;
use App\Scrapers\ScraperRunService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('scraper:run {grocer : The grocer scraper key, e.g. rema1000} {--limit= : Limit discovered products for live smoke tests} {--no-delay : Disable scraper politeness delays for tests only}')]
#[Description('Fetch and import active papers for a grocer scraper')]
class RunScraperCommand extends Command
{
    public function handle(ScraperRunService $scraperRunService): int
    {
        try {
            $limit = $this->option('limit') === null ? null : (int) $this->option('limit');
            $result = $scraperRunService->run(
                (string) $this->argument('grocer'),
                $limit > 0 ? $limit : null,
                ! (bool) $this->option('no-delay'),
                function (string $message): void {
                    $this->line($message);
                },
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

        return self::SUCCESS;
    }
}
