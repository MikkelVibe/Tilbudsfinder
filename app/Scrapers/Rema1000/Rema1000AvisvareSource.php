<?php

namespace App\Scrapers\Rema1000;

use App\Scrapers\Exceptions\ScraperFetchException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class Rema1000AvisvareSource
{
    private const URL = 'https://flwdn2189e-dsn.algolia.net/1/indexes/*/queries';

    private const APPLICATION_ID = 'FLWDN2189E';

    private const API_KEY = 'fa20981a63df668e871a87a8fbd0caed';

    private const INDEX = 'aws-prod-products';

    /**
     * @return array<string, array<string, mixed>>
     */
    public function products(): array
    {
        $response = $this->http()
            ->post(self::URL, [
                'requests' => [[
                    'indexName' => self::INDEX,
                    'params' => 'query=&hitsPerPage=9999&facets=%5B%22labels%22%5D&facetFilters=%5B%22labels%3Aavisvare%22%5D&filters=',
                ]],
            ])
            ->throw()
            ->json();

        $hits = Arr::get($response, 'results.0.hits');

        if (! is_array($hits)) {
            throw new ScraperFetchException('REMA 1000 Algolia response did not contain hits.');
        }

        $products = [];

        foreach ($hits as $hit) {
            if (! is_array($hit) || ! in_array('avisvare', Arr::get($hit, 'labels', []), true)) {
                continue;
            }

            $id = (string) Arr::get($hit, 'id', '');

            if ($id === '') {
                continue;
            }

            $products[$id] = $hit;
        }

        if ($products === []) {
            throw new ScraperFetchException('REMA 1000 Algolia returned no avisvare products.');
        }

        return $products;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->withQueryParameters([
                'x-algolia-agent' => 'Algolia for vanilla JavaScript 3.21.1',
                'x-algolia-application-id' => self::APPLICATION_ID,
                'x-algolia-api-key' => self::API_KEY,
            ]);
    }
}
