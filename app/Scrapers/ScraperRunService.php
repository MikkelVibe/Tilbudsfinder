<?php

namespace App\Scrapers;

use App\Imports\Exceptions\DuplicatePaperImportException;
use App\Imports\ImportPersistencePipeline;
use App\Models\Grocer;
use App\Scrapers\DTO\ScraperPaperRunResult;
use App\Scrapers\DTO\ScraperRunResult;
use App\Scrapers\Exceptions\ScraperRunException;
use Throwable;

class ScraperRunService
{
    public function __construct(
        private readonly ImportPersistencePipeline $pipeline,
        private readonly ScraperRegistry $scrapers,
    ) {}

    public function run(string $grocerKey): ScraperRunResult
    {
        $scraper = $this->scrapers->get($grocerKey);
        $grocer = Grocer::query()->where('slug', $scraper->grocerKey())->first();

        if (! $grocer) {
            throw new ScraperRunException("Grocer [{$scraper->grocerKey()}] does not exist.");
        }

        $papers = $scraper->fetchPapers();
        $importedCount = 0;
        $skippedDuplicateCount = 0;
        $failedCount = 0;
        $paperResults = [];

        foreach ($papers as $paper) {
            try {
                $this->pipeline->persist($grocer, $paper);
                $importedCount++;
                $paperResults[] = ScraperPaperRunResult::imported($paper->sourceExternalId);
            } catch (DuplicatePaperImportException) {
                $skippedDuplicateCount++;
                $paperResults[] = ScraperPaperRunResult::duplicate($paper->sourceExternalId);
            } catch (Throwable $exception) {
                report($exception);

                $failedCount++;
                $paperResults[] = ScraperPaperRunResult::failed($paper->sourceExternalId, $exception->getMessage());
            }
        }

        return new ScraperRunResult(
            grocerKey: $scraper->grocerKey(),
            fetchedPaperCount: count($papers),
            importedPaperCount: $importedCount,
            skippedDuplicateCount: $skippedDuplicateCount,
            failedPaperCount: $failedCount,
            papers: $paperResults,
        );
    }
}
