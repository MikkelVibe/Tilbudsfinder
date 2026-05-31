<?php

namespace App\Scrapers;

use App\Imports\Exceptions\DuplicatePaperImportException;
use App\Imports\ImportPersistencePipeline;
use App\Models\Grocer;
use App\Models\ScrapeJob;
use App\Scrapers\Bilka\BilkaScraper;
use App\Scrapers\Coop\CoopBanner;
use App\Scrapers\Coop\CoopTjekScraper;
use App\Scrapers\Dagrofa\MenyScraper;
use App\Scrapers\Dagrofa\MinKobmandScraper;
use App\Scrapers\Dagrofa\SparScraper;
use App\Scrapers\DTO\ScraperRunResult;
use App\Scrapers\Exceptions\ScraperRunException;
use App\Scrapers\Foetex\FoetexScraper;
use App\Scrapers\Netto\NettoScraper;
use App\Scrapers\Rema1000\Rema1000Scraper;

class ScraperRunService
{
    public function __construct(
        private readonly ImportPersistencePipeline $pipeline = new ImportPersistencePipeline,
    ) {}

    public function run(string $grocerKey, ?int $limit = null, bool $sleepBetweenDetailRequests = true, ?callable $progress = null, ?ScrapeJob $scrapeJob = null): ScraperRunResult
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
                $this->pipeline->persist($grocer, $scraper->parse($payload), $scrapeJob);
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

    public function scraperFor(string $grocerKey, bool $sleepBetweenDetailRequests = true): GrocerScraper
    {
        return $this->scraper($grocerKey, $sleepBetweenDetailRequests);
    }

    private function scraper(string $grocerKey, bool $sleepBetweenDetailRequests): GrocerScraper
    {
        return match ($grocerKey) {
            '365discount' => new CoopTjekScraper(CoopBanner::discount365()),
            'bilka' => new BilkaScraper,
            'daglibrugsen' => new CoopTjekScraper(CoopBanner::daglibrugsen()),
            'foetex' => new FoetexScraper,
            'kvickly' => new CoopTjekScraper(CoopBanner::kvickly()),
            'meny' => new MenyScraper,
            'minkobmand' => new MinKobmandScraper,
            'netto' => new NettoScraper,
            'rema1000' => new Rema1000Scraper(sleepBetweenDetailRequests: $sleepBetweenDetailRequests),
            'spar' => new SparScraper,
            'superbrugsen' => new CoopTjekScraper(CoopBanner::superbrugsen()),
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
