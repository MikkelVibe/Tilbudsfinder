<?php

namespace App\Scrapers;

use App\Imports\DTO\ParsedPaperInput;

interface GrocerScraper
{
    public function grocerKey(): string;

    /**
     * @return list<ParsedPaperInput>
     */
    public function fetchPapers(): array;
}
