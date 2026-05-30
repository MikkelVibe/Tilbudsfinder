<?php

namespace App\Scrapers\DTO;

readonly class ScraperRunResult
{
    /**
     * @param  list<ScraperPaperRunResult>  $papers
     */
    public function __construct(
        public string $grocerKey,
        public int $fetchedPaperCount,
        public int $importedPaperCount,
        public int $skippedDuplicateCount,
        public int $failedPaperCount,
        public array $papers,
    ) {}

    public function hasFailures(): bool
    {
        return $this->failedPaperCount > 0;
    }
}
