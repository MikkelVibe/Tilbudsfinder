<?php

namespace App\Scrapers;

use App\Enums\GrocerHealthStatus;
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
use App\Scrapers\DTO\RawPaperPayload;
use App\Scrapers\DTO\ScraperRunResult;
use App\Scrapers\Exceptions\ScraperRunException;
use App\Scrapers\Foetex\FoetexScraper;
use App\Scrapers\Nemlig\NemligScraper;
use App\Scrapers\Netto\NettoScraper;
use App\Scrapers\Rema1000\Rema1000Scraper;
use Throwable;

class ScraperRunService
{
    public function __construct(
        private readonly ImportPersistencePipeline $pipeline = new ImportPersistencePipeline,
        private readonly PaperExistenceChecker $paperExistenceChecker = new PaperExistenceChecker,
    ) {}

    public function run(string $grocerKey, ?callable $progress = null, ?ScrapeJob $scrapeJob = null, bool $skipKnown = false): ScraperRunResult
    {
        $scraper = $this->scraper($grocerKey);
        $grocer = Grocer::query()->where('slug', $scraper->grocerKey())->first();

        if (! $grocer) {
            throw new ScraperRunException("Grocer [{$scraper->grocerKey()}] does not exist.");
        }

        $candidates = $scraper->discoverPapers($progress);
        $knownPapers = $skipKnown
            ? $this->paperExistenceChecker->check($scraper->grocerKey(), array_map(fn ($candidate): string => $candidate->sourceExternalId, $candidates))
            : [];
        $payloads = $scraper->fetchPapers($candidates, $knownPapers, progress: $progress);

        return $this->importPayloads($grocer, $scraper, $payloads, $progress, $scrapeJob);
    }

    /**
     * @param  list<RawPaperPayload>  $payloads
     */
    public function importPayloads(Grocer $grocer, GrocerScraper $scraper, array $payloads, ?callable $progress = null, ?ScrapeJob $scrapeJob = null): ScraperRunResult
    {
        $importedCount = 0;
        $skippedDuplicateCount = 0;
        $failures = [];

        foreach ($payloads as $payload) {
            if ($payload->alreadyFetched) {
                $skippedDuplicateCount++;
                $this->progress($progress, "Skipped already imported paper {$payload->sourceExternalId}.");

                continue;
            }

            try {
                $this->progress($progress, "Importing paper {$payload->sourceExternalId} ({$payload->title})...");
                $this->pipeline->persist($grocer, $scraper->parse($payload), $scrapeJob);
                $importedCount++;
                $this->progress($progress, "Imported paper {$payload->sourceExternalId}.");
            } catch (DuplicatePaperImportException) {
                $skippedDuplicateCount++;
                $this->progress($progress, "Skipped duplicate paper {$payload->sourceExternalId}.");
            } catch (Throwable $exception) {
                report($exception);

                $failureMessage = "Paper {$payload->sourceExternalId} failed: {$exception->getMessage()}";
                $failures[] = $failureMessage;
                $this->progress($progress, $failureMessage);
            }
        }

        if ($failures !== []) {
            $scrapeJob?->update([
                'context' => [
                    ...($scrapeJob->context ?? []),
                    'fetched_paper_count' => count($payloads),
                    'imported_paper_count' => $importedCount,
                    'skipped_duplicate_count' => $skippedDuplicateCount,
                ],
            ]);

            $grocer->update([
                'health_status' => GrocerHealthStatus::Failing,
                'last_failure_at' => now(),
            ]);

            throw new ScraperRunException(implode(' ', $failures));
        }

        return new ScraperRunResult(
            grocerKey: $scraper->grocerKey(),
            fetchedPaperCount: count($payloads),
            importedPaperCount: $importedCount,
            skippedDuplicateCount: $skippedDuplicateCount,
        );
    }

    public function scraperFor(string $grocerKey): GrocerScraper
    {
        return $this->scraper($grocerKey);
    }

    private function scraper(string $grocerKey): GrocerScraper
    {
        return match ($grocerKey) {
            '365discount' => new CoopTjekScraper(CoopBanner::discount365()),
            'bilka' => new BilkaScraper,
            'daglibrugsen' => new CoopTjekScraper(CoopBanner::daglibrugsen()),
            'foetex' => new FoetexScraper,
            'kvickly' => new CoopTjekScraper(CoopBanner::kvickly()),
            'meny' => new MenyScraper,
            'minkobmand' => new MinKobmandScraper,
            'nemlig' => new NemligScraper,
            'netto' => new NettoScraper,
            'rema1000' => new Rema1000Scraper,
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
