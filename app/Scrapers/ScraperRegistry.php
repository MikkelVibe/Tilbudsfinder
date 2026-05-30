<?php

namespace App\Scrapers;

use App\Scrapers\Exceptions\ScraperRunException;

class ScraperRegistry
{
    /** @var array<string, GrocerScraper> */
    private readonly array $scrapers;

    /**
     * @param  list<GrocerScraper>  $scrapers
     */
    public function __construct(array $scrapers = [])
    {
        $indexedScrapers = [];

        foreach ($scrapers as $scraper) {
            $indexedScrapers[$scraper->grocerKey()] = $scraper;
        }

        $this->scrapers = $indexedScrapers;
    }

    public function get(string $grocerKey): GrocerScraper
    {
        if (! isset($this->scrapers[$grocerKey])) {
            throw new ScraperRunException("Scraper [{$grocerKey}] is not supported.");
        }

        return $this->scrapers[$grocerKey];
    }
}
