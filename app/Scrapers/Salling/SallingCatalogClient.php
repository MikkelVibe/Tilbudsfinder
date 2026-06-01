<?php

namespace App\Scrapers\Salling;

use App\Scrapers\Exceptions\ScraperFetchException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class SallingCatalogClient
{
    private const DEFAULT_PAGE_SIZE = 1000;

    /**
     * @return list<string>
     */
    private const ATTRIBUTES = [
        'objectID',
        'id',
        'pid',
        'article',
        'active_gtin',
        'gtins',
        'name',
        'brand',
        'subBrand',
        'description',
        'netcontent',
        'net_content',
        'net_content_unit_of_measure',
        'net_content_unit_of_measure_display',
        'units',
        'unitsOfMeasure',
        'infos',
        'storeData',
        'isInCurrentLeaflet',
        'isInOffer',
        'cpOffer',
        'cpOfferFromDate',
        'cpOfferToDate',
        'cpOfferPrice',
        'cpOfferAmount',
        'cpOfferId',
        'sales_price',
        'list_price',
        'promotion_text',
        'promotion_start_date',
        'promotion_end_date',
        'f_campaign_name',
        'format_name',
    ];

    public function fetch(string $catalogKey, ?int $limit = null): SallingCatalogResult
    {
        $catalog = SallingCatalog::forKey($catalogKey);
        $pageSize = $limit === null ? self::DEFAULT_PAGE_SIZE : min(self::DEFAULT_PAGE_SIZE, max(1, $limit));
        $page = 0;
        $products = [];
        $totalHits = 0;
        $fetchedHits = 0;

        do {
            $response = $this->http($catalog)->post($catalog->host().'/1/indexes/'.$catalog->indexName.'/query', [
                'query' => '*',
                'filters' => $catalog->filters,
                'attributesToRetrieve' => self::ATTRIBUTES,
                'hitsPerPage' => $pageSize,
                'page' => $page,
            ])->throw()->json();

            if (! is_array($response)) {
                throw new ScraperFetchException("Salling catalog [{$catalogKey}] response was not an object.");
            }

            $totalHits = is_numeric(Arr::get($response, 'nbHits')) ? (int) Arr::get($response, 'nbHits') : $totalHits;
            $hits = array_values(array_filter(Arr::get($response, 'hits', []), 'is_array'));

            foreach ($hits as $hit) {
                $fetchedHits++;
                $products[] = $this->product($catalog, $hit);

                if ($limit !== null && count($products) >= $limit) {
                    break 2;
                }
            }

            $page++;
            $nbPages = is_numeric(Arr::get($response, 'nbPages')) ? (int) Arr::get($response, 'nbPages') : $page;
        } while ($page < $nbPages && $hits !== []);

        return new SallingCatalogResult(
            catalog: $catalog,
            products: $products,
            totalHits: $totalHits,
            fetchedHits: $fetchedHits,
        );
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private function product(SallingCatalog $catalog, array $hit): SallingCatalogProduct
    {
        return new SallingCatalogProduct(
            source: $catalog->key,
            sourceProductId: $this->requiredScalarString($hit, 'objectID'),
            title: $this->title($hit),
            eans: $this->eans($catalog, $hit),
            brand: $this->optionalString($hit, 'brand'),
            packageText: $this->packageText($catalog, $hit),
            payload: $hit,
        );
    }

    /**
     * @param  array<string, mixed>  $hit
     * @return list<string>
     */
    private function eans(SallingCatalog $catalog, array $hit): array
    {
        $values = match ($catalog->key) {
            'bilkatogo' => [$this->bilkaToGoInfoValue($hit, 'EAN')],
            'foetex' => [Arr::get($hit, 'active_gtin'), ...Arr::wrap(Arr::get($hit, 'gtins', []))],
            default => [],
        };

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?string => is_scalar($value) && preg_match('/^\d{8,14}$/', (string) $value) === 1 ? (string) $value : null,
            $values,
        ))));
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private function title(array $hit): string
    {
        $name = $this->optionalString($hit, 'name') ?? $this->optionalString($hit, 'description');

        if ($name === null) {
            throw new ScraperFetchException('Salling catalog product is missing name.');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private function packageText(SallingCatalog $catalog, array $hit): ?string
    {
        if ($catalog->key === 'bilkatogo') {
            return $this->optionalString($hit, 'netcontent')
                ?? trim(implode(' ', array_filter([
                    $this->optionalScalarString($hit, 'units'),
                    $this->optionalString($hit, 'unitsOfMeasure'),
                ], static fn (?string $value): bool => $value !== null && trim($value) !== ''))) ?: null;
        }

        return $this->optionalString($hit, 'net_content') === null
            ? null
            : trim($this->optionalString($hit, 'net_content').' '.($this->optionalString($hit, 'net_content_unit_of_measure_display') ?? $this->optionalString($hit, 'net_content_unit_of_measure') ?? ''));
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    private function bilkaToGoInfoValue(array $hit, string $title): ?string
    {
        $infos = Arr::get($hit, 'infos', []);

        if (! is_array($infos)) {
            return null;
        }

        foreach ($infos as $info) {
            if (! is_array($info)) {
                continue;
            }

            $items = Arr::get($info, 'items', []);

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (is_array($item) && mb_strtolower((string) Arr::get($item, 'title')) === mb_strtolower($title)) {
                    return $this->optionalScalarString($item, 'value');
                }
            }
        }

        return null;
    }

    private function http(SallingCatalog $catalog): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders([
                'X-Algolia-Api-Key' => $catalog->apiKey,
                'X-Algolia-Application-Id' => $catalog->appId,
            ])
            ->timeout(30)
            ->connectTimeout(5)
            ->retry([200, 500]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredScalarString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new ScraperFetchException("Salling catalog product is missing {$key}.");
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalScalarString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        if (is_scalar($value) && trim((string) $value) !== '') {
            return (string) $value;
        }

        return null;
    }
}
