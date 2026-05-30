<?php

namespace App\Scrapers\Rema1000;

use App\Imports\DTO\ParsedPaperInput;
use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\GrocerScraper;
use App\Scrapers\Rema1000\DTO\Rema1000Catalog;

class Rema1000Scraper implements GrocerScraper
{
    public function __construct(
        private readonly Rema1000PaperMapper $mapper,
        private readonly Rema1000CatalogSource $catalogs,
        private readonly Rema1000AvisvareSource $avisvarer,
        private readonly Rema1000ProductDetailSource $details,
        private readonly Rema1000OfferGrouper $grouper,
    ) {}

    public function grocerKey(): string
    {
        return 'rema1000';
    }

    /**
     * @return list<ParsedPaperInput>
     */
    public function fetchPapers(): array
    {
        $catalogs = $this->catalogs->activeCatalogs();
        $products = $this->avisvarer->products();
        $details = $this->details->details(array_keys($products));
        $groupedOffers = $this->grouper->group($products, $details, $catalogs);
        $papers = $this->papers($catalogs, $groupedOffers);

        if ($papers === []) {
            throw new ScraperFetchException('REMA 1000 found no advertised product offers matching weekly active catalogs.');
        }

        return $papers;
    }

    /**
     * @param  list<Rema1000Catalog>  $catalogs
     * @param  array<string, list<array<string, mixed>>>  $groupedOffers
     * @return list<ParsedPaperInput>
     */
    private function papers(array $catalogs, array $groupedOffers): array
    {
        $papers = [];

        foreach ($catalogs as $catalog) {
            $catalogId = $catalog->requiredId();

            if (! isset($groupedOffers[$catalogId])) {
                continue;
            }

            $papers[] = $this->mapper->map($catalog, $groupedOffers[$catalogId]);
        }

        return $papers;
    }
}
