<?php

namespace App\Scrapers;

use App\Imports\DTO\ParsedPaperInput;
use App\Scrapers\DTO\RawPaperPayload;

interface GrocerScraper
{
    public function grocerKey(): string;

    /**
     * @return list<RawPaperPayload>
     */
    public function fetchPapers(?int $limit = null, ?callable $progress = null): array;

    public function parse(RawPaperPayload $payload): ParsedPaperInput;
}
