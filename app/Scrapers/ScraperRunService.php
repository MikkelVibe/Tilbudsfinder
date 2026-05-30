<?php

namespace App\Scrapers;

use App\Imports\Exceptions\DuplicatePaperImportException;
use App\Imports\ImportPersistencePipeline;
use App\Models\Grocer;
use App\Scrapers\DTO\ScraperRunResult;
use App\Scrapers\Exceptions\ScraperRunException;
use App\Scrapers\Rema1000\Rema1000Scraper;

class ScraperRunService
{
    public function __construct(
        private readonly ImportPersistencePipeline $pipeline = new ImportPersistencePipeline,
    ) {}

    public function run(string $grocerKey, ?int $limit = null, bool $sleepBetweenDetailRequests = true, ?callable $progress = null): ScraperRunResult
    {
        $scraper = $this->scraper($grocerKey, $sleepBetweenDetailRequests);
        $grocer = Grocer::query()->where('slug', $scraper->grocerKey())->first();

        if (! $grocer) {
            throw new ScraperRunException("Grocer [{$scraper->grocerKey()}] does not exist.");
        }

        $payloads = $scraper->fetchPapers($limit, $progress);
        $importedCount = 0;
        $skippedDuplicateCount = 0;

        foreach ($payloads as $payload) {
            try {
                $this->progress($progress, "Importing paper {$payload->sourceExternalId} ({$payload->title})...");
                $this->pipeline->persist($grocer, $scraper->parse($payload));
                $importedCount++;
                $this->progress($progress, "Imported paper {$payload->sourceExternalId}.");
            } catch (DuplicatePaperImportException) {
                $skippedDuplicateCount++;
                $this->progress($progress, "Skipped duplicate paper {$payload->sourceExternalId}.");
            }
        }

        return new ScraperRunResult(
            grocerKey: $scraper->grocerKey(),
            fetchedPaperCount: count($payloads),
            importedPaperCount: $importedCount,
            skippedDuplicateCount: $skippedDuplicateCount,
        );
    }

    private function scraper(string $grocerKey, bool $sleepBetweenDetailRequests): GrocerScraper
    {
        return match ($grocerKey) {
            'rema1000' => new Rema1000Scraper(sleepBetweenDetailRequests: $sleepBetweenDetailRequests),
            default => throw new ScraperRunException("Scraper [{$grocerKey}] is not supported."),
        };
    }

    private function progress(?callable $progress, string $message): void
    {
        if ($progress !== null) {
            $progress($message);
        }
    }
}
