<?php

namespace App\Scrapers\DTO;

readonly class ScraperRunResult
{
    public function __construct(
        public string $grocerKey,
        public int $fetchedPaperCount,
        public int $importedPaperCount,
        public int $skippedDuplicateCount,
    ) {}
}
