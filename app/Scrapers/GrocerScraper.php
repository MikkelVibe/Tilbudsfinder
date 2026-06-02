<?php

namespace App\Scrapers;

use App\Imports\DTO\ParsedPaperInput;
use App\Scrapers\DTO\PaperCandidate;
use App\Scrapers\DTO\RawPaperPayload;

interface GrocerScraper
{
    public function grocerKey(): string;

    /**
     * @return list<PaperCandidate>
     */
    public function discoverPapers(?callable $progress = null): array;

    /**
     * @param  list<PaperCandidate>  $candidates
     * @param  array<string, array{exists: bool, title?: ?string, active_from?: ?string, active_until?: ?string}>  $knownPapers
     * @return list<RawPaperPayload>
     */
    public function fetchPapers(array $candidates, array $knownPapers = [], ?int $limit = null, ?callable $progress = null): array;

    public function parse(RawPaperPayload $payload): ParsedPaperInput;
}
