<?php

namespace App\Scrapers\Rema1000;

use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\Rema1000\DTO\Rema1000Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class Rema1000CatalogSource
{
    private const BASE_URL = 'https://squid-api.tjek.com/v2';

    private const DEALER_ID = '11deC';

    /**
     * @return list<Rema1000Catalog>
     */
    public function activeCatalogs(): array
    {
        $catalogs = $this->http()
            ->get(self::BASE_URL.'/catalogs', [
                'dealer_id' => self::DEALER_ID,
                'order_by' => '-publication_date',
                'offset' => 0,
                'limit' => 24,
                'types' => 'paged,incito',
            ])
            ->throw()
            ->json();

        if (! is_array($catalogs)) {
            throw new ScraperFetchException('REMA 1000 catalog response was not an array.');
        }

        $now = CarbonImmutable::now();
        $eligibleCatalogs = [];

        foreach ($catalogs as $catalog) {
            if (! is_array($catalog)) {
                continue;
            }

            $catalog = new Rema1000Catalog($catalog);

            if ($catalog->isEligibleWeeklyPaper($now)) {
                $eligibleCatalogs[] = $catalog;
            }
        }

        return $eligibleCatalogs;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([200, 500]);
    }
}
