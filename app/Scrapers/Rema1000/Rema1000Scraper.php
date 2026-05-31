<?php

namespace App\Scrapers\Rema1000;

use App\Imports\DTO\ParsedPaperInput;
use App\Scrapers\DTO\RawPaperPayload;
use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\GrocerScraper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;

class Rema1000Scraper implements GrocerScraper
{
    private const ALGOLIA_URL = 'https://flwdn2189e-dsn.algolia.net/1/indexes/*/queries';

    private const ALGOLIA_APPLICATION_ID = 'FLWDN2189E';

    private const ALGOLIA_API_KEY = 'fa20981a63df668e871a87a8fbd0caed';

    private const ALGOLIA_INDEX = 'aws-prod-products';

    private const REMA_API_URL = 'https://api.digital.rema1000.dk/api';

    private const REMA_CATALOG_URL = 'https://cphapp.rema1000.dk/api/v1/catalog/store/1/withchildren';

    private const TJEK_API_URL = 'https://squid-api.tjek.com/v2';

    private const TJEK_DEALER_ID = '11deC';

    private const PRODUCT_DETAIL_DELAY_MICROSECONDS = 1_000_000;

    private const PRODUCT_DETAIL_JITTER_MICROSECONDS = 500_000;

    private const MINIMUM_DETAIL_COVERAGE_PERCENT = 95;

    public function __construct(
        private readonly Rema1000PaperParser $parser = new Rema1000PaperParser,
        private readonly bool $sleepBetweenDetailRequests = true,
    ) {}

    public function grocerKey(): string
    {
        return 'rema1000';
    }

    /**
     * @return list<RawPaperPayload>
     */
    public function fetchPapers(?int $limit = null, ?callable $progress = null): array
    {
        $this->progress($progress, 'Fetching REMA 1000 Tjek catalogs...');
        $catalogs = $this->fetchActiveCatalogs();
        $this->progress($progress, 'Found '.count($catalogs).' active REMA 1000 catalogs.');

        $weeklyCatalogs = array_values(array_filter($catalogs, fn (array $catalog): bool => $this->isWeeklyCatalog($catalog)));
        $this->progress($progress, 'Found '.count($weeklyCatalogs).' active REMA 1000 Uge catalogs eligible for publishing.');

        $this->progress($progress, 'Fetching REMA 1000 Algolia avisvare products...');
        $algoliaProducts = $this->fetchAvisvarerProducts($limit);
        $this->progress($progress, 'Found '.count($algoliaProducts).' REMA 1000 Algolia avisvare products'.($limit ? ' after limit' : '').'.');

        $this->progress($progress, 'Fetching REMA 1000 catalog v1 advertised products...');
        $catalogProducts = $this->fetchCatalogAdvertisedProducts($limit);
        $this->progress($progress, 'Found '.count($catalogProducts).' REMA 1000 catalog v1 advertised products'.($limit ? ' after limit' : '').'.');

        $comparison = $this->compareDiscoverySources($algoliaProducts, $catalogProducts);
        $this->progress($progress, 'REMA 1000 discovery comparison: '.count($comparison['missing_from_catalog']).' missing from catalog; '.count($comparison['missing_from_algolia']).' missing from Algolia.');

        $products = $this->mergeDiscoveredProducts($algoliaProducts, $catalogProducts);
        $this->progress($progress, 'Using '.count($products).' REMA 1000 discovered advertised products after source union.');

        $details = $this->fetchProductDetails(array_keys($products), $progress);
        $this->progress($progress, 'Grouping REMA 1000 products by best Tjek catalog overlap...');
        $groupedOffers = $this->groupProductOffers($products, $details, $catalogs, $comparison);

        if ($groupedOffers === []) {
            throw new ScraperFetchException('REMA 1000 found no advertised product offers matching active catalogs.');
        }

        foreach ($groupedOffers as $catalogId => $offers) {
            $catalog = $this->catalogById($catalogs, $catalogId);
            $this->progress($progress, 'Grouped '.count($offers).' products into '.($this->optionalString($catalog ?? [], 'label') ?? $catalogId).'.');
        }

        return array_map(
            fn (array $catalog): RawPaperPayload => $this->rawPayload($catalog, $groupedOffers[$this->requiredString($catalog, 'id')]),
            array_values(array_filter($catalogs, fn (array $catalog): bool => $this->isWeeklyCatalog($catalog) && isset($groupedOffers[$this->requiredString($catalog, 'id')]))),
        );
    }

    public function parse(RawPaperPayload $payload): ParsedPaperInput
    {
        return $this->parser->parse($payload->rawPayload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchActiveCatalogs(): array
    {
        $catalogs = $this->tjekHttp()
            ->get(self::TJEK_API_URL.'/catalogs', [
                'dealer_id' => self::TJEK_DEALER_ID,
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

        return array_values(array_filter(array_filter($catalogs, 'is_array'), function (array $catalog) use ($now): bool {
            $offerCount = Arr::get($catalog, 'offer_count');
            $activeFrom = $this->date($catalog, 'run_from');
            $activeUntil = $this->date($catalog, 'run_till');

            return is_numeric($offerCount)
                && (int) $offerCount >= 10
                && $activeFrom !== null
                && $activeUntil !== null
                && $activeFrom->lessThanOrEqualTo($now)
                && $activeUntil->greaterThanOrEqualTo($now);
        }));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchAvisvarerProducts(?int $limit): array
    {
        $response = $this->algoliaHttp()
            ->post(self::ALGOLIA_URL, [
                'requests' => [[
                    'indexName' => self::ALGOLIA_INDEX,
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

            if ($limit !== null && count($products) >= $limit) {
                break;
            }
        }

        if ($products === []) {
            throw new ScraperFetchException('REMA 1000 Algolia returned no avisvare products.');
        }

        return $products;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchCatalogAdvertisedProducts(?int $limit): array
    {
        $response = $this->catalogHttp()
            ->get(self::REMA_CATALOG_URL)
            ->throw()
            ->json();

        $departments = Arr::get($response, 'departments');

        if (! is_array($departments)) {
            throw new ScraperFetchException('REMA 1000 catalog v1 response did not contain departments.');
        }

        $products = [];

        foreach ($departments as $department) {
            if (! is_array($department)) {
                continue;
            }

            foreach (Arr::get($department, 'categories', []) as $category) {
                if (! is_array($category)) {
                    continue;
                }

                foreach (Arr::get($category, 'items', []) as $item) {
                    if (! is_array($item) || ! $this->isCatalogAdvertisedProduct($item)) {
                        continue;
                    }

                    $id = (string) Arr::get($item, 'id', '');

                    if ($id === '') {
                        continue;
                    }

                    $products[$id] = [
                        ...$item,
                        'department_name' => $this->optionalString($department, 'name'),
                        'category_name' => $this->optionalString($category, 'name'),
                    ];

                    if ($limit !== null && count($products) >= $limit) {
                        return $products;
                    }
                }
            }
        }

        if ($products === []) {
            throw new ScraperFetchException('REMA 1000 catalog v1 returned no advertised products.');
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isCatalogAdvertisedProduct(array $item): bool
    {
        return Arr::get($item, 'pricing.is_advertised') === true
            || in_array('avisvare', Arr::get($item, 'labels', []), true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $algoliaProducts
     * @param  array<string, array<string, mixed>>  $catalogProducts
     * @return array{missing_from_catalog: list<string>, missing_from_algolia: list<string>}
     */
    private function compareDiscoverySources(array $algoliaProducts, array $catalogProducts): array
    {
        return [
            'missing_from_catalog' => array_values(array_diff(array_keys($algoliaProducts), array_keys($catalogProducts))),
            'missing_from_algolia' => array_values(array_diff(array_keys($catalogProducts), array_keys($algoliaProducts))),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $algoliaProducts
     * @param  array<string, array<string, mixed>>  $catalogProducts
     * @return array<string, array{algolia: array<string, mixed>|null, catalog: array<string, mixed>|null}>
     */
    private function mergeDiscoveredProducts(array $algoliaProducts, array $catalogProducts): array
    {
        $ids = array_unique([...array_keys($algoliaProducts), ...array_keys($catalogProducts)]);
        sort($ids);

        $products = [];

        foreach ($ids as $id) {
            $products[$id] = [
                'algolia' => $algoliaProducts[$id] ?? null,
                'catalog' => $catalogProducts[$id] ?? null,
            ];
        }

        return $products;
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, array<string, mixed>>
     */
    private function fetchProductDetails(array $productIds, ?callable $progress = null): array
    {
        $details = [];
        $failedProductIds = [];
        $total = count($productIds);

        $this->progress($progress, "Fetching {$total} REMA 1000 product details one-by-one...");

        foreach ($productIds as $index => $productId) {
            if ($index > 0) {
                $this->sleepBetweenDetailRequests();
            }

            try {
                $response = $this->productHttp()
                    ->get(self::REMA_API_URL."/v3/products/{$productId}", [
                        'include' => 'declaration,nutrition_info,declaration,warnings',
                    ])
                    ->throw()
                    ->json();
            } catch (\Throwable) {
                $failedProductIds[] = $productId;
                $this->progress($progress, 'Failed REMA 1000 product detail '.($index + 1)."/{$total} ({$productId}).");

                continue;
            }

            $detail = Arr::get($response, 'data');
            $id = (string) Arr::get($detail, 'id', '');

            if (is_array($detail) && $id !== '') {
                $details[$id] = $detail;
            } else {
                $failedProductIds[] = $productId;
            }

            if (($index + 1) % 25 === 0 || $index === 0 || $index + 1 === $total) {
                $this->progress($progress, 'Fetched REMA 1000 product details '.($index + 1)."/{$total}; successes: ".count($details).'; failures: '.count($failedProductIds).'.');
            }
        }

        $coverage = count($productIds) === 0 ? 0 : (count($details) / count($productIds)) * 100;

        if ($coverage < self::MINIMUM_DETAIL_COVERAGE_PERCENT) {
            throw new ScraperFetchException('REMA 1000 product detail coverage was '.round($coverage, 2).'%, below '.self::MINIMUM_DETAIL_COVERAGE_PERCENT.'%. Failed products: '.implode(', ', array_slice($failedProductIds, 0, 10)));
        }

        return $details;
    }

    /**
     * @param  list<array<string, mixed>>  $catalogs
     * @return array<string, mixed>|null
     */
    private function catalogById(array $catalogs, string $catalogId): ?array
    {
        foreach ($catalogs as $catalog) {
            if ($this->optionalString($catalog, 'id') === $catalogId) {
                return $catalog;
            }
        }

        return null;
    }

    private function progress(?callable $progress, string $message): void
    {
        if ($progress !== null) {
            $progress($message);
        }
    }

    /**
     * @param  array<string, array{algolia: array<string, mixed>|null, catalog: array<string, mixed>|null}>  $products
     * @param  array<string, array<string, mixed>>  $details
     * @param  list<array<string, mixed>>  $catalogs
     * @param  array{missing_from_catalog: list<string>, missing_from_algolia: list<string>}  $comparison
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupProductOffers(array $products, array $details, array $catalogs, array $comparison): array
    {
        $groups = [];

        foreach ($products as $productId => $product) {
            $detail = $details[$productId] ?? null;

            if ($detail === null) {
                continue;
            }

            $advertisedPrice = $this->advertisedPrice($detail);

            if ($advertisedPrice === null) {
                continue;
            }

            $catalog = $this->bestCatalogOverlap($advertisedPrice, $catalogs);

            if ($catalog === null) {
                continue;
            }

            $catalogId = $this->requiredString($catalog, 'id');
            $groups[$catalogId] ??= [];
            $groups[$catalogId][] = [
                'algolia' => $product['algolia'],
                'catalog_product' => $product['catalog'],
                'product_detail' => $detail,
                'advertised_price' => $advertisedPrice,
                'discovery_comparison' => [
                    'missing_from_catalog' => in_array($productId, $comparison['missing_from_catalog'], true),
                    'missing_from_algolia' => in_array($productId, $comparison['missing_from_algolia'], true),
                ],
            ];
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    private function advertisedPrice(array $detail): ?array
    {
        $prices = Arr::get($detail, 'prices');

        if (! is_array($prices)) {
            return null;
        }

        foreach ($prices as $price) {
            if (is_array($price) && Arr::get($price, 'is_advertised') === true && $this->date($price, 'starting_at') && $this->date($price, 'ending_at')) {
                return $price;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $advertisedPrice
     * @param  list<array<string, mixed>>  $catalogs
     * @return array<string, mixed>|null
     */
    private function bestCatalogOverlap(array $advertisedPrice, array $catalogs): ?array
    {
        $offerStart = $this->date($advertisedPrice, 'starting_at');
        $offerEnd = $this->date($advertisedPrice, 'ending_at');
        $bestCatalog = null;
        $bestOverlap = 0;
        $bestCatalogDuration = PHP_INT_MAX;

        if (! $offerStart || ! $offerEnd) {
            return null;
        }

        foreach ($catalogs as $catalog) {
            $catalogStart = $this->date($catalog, 'run_from');
            $catalogEnd = $this->date($catalog, 'run_till');

            if (! $catalogStart || ! $catalogEnd) {
                continue;
            }

            $overlapStart = $offerStart->greaterThan($catalogStart) ? $offerStart : $catalogStart;
            $overlapEnd = $offerEnd->lessThan($catalogEnd) ? $offerEnd : $catalogEnd;
            $overlap = $overlapStart->lessThan($overlapEnd) ? $overlapEnd->getTimestamp() - $overlapStart->getTimestamp() : 0;
            $catalogDuration = $catalogEnd->getTimestamp() - $catalogStart->getTimestamp();

            if ($overlap > $bestOverlap || ($overlap === $bestOverlap && $overlap > 0 && $catalogDuration < $bestCatalogDuration)) {
                $bestCatalog = $catalog;
                $bestOverlap = $overlap;
                $bestCatalogDuration = $catalogDuration;
            }
        }

        return $bestCatalog;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @param  list<array<string, mixed>>  $offers
     */
    private function rawPayload(array $catalog, array $offers): RawPaperPayload
    {
        $catalogId = $this->requiredString($catalog, 'id');
        $rawPayload = $this->encode([
            'catalog' => [
                ...$catalog,
                'source_strategy' => 'algolia_product_details_grouped_by_tjek_overlap',
                'fetched_product_offer_count' => count($offers),
            ],
            'offers' => $offers,
        ]);

        return new RawPaperPayload(
            sourceExternalId: $catalogId,
            rawPayload: $rawPayload,
            title: $this->optionalString($catalog, 'label'),
        );
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function isWeeklyCatalog(array $catalog): bool
    {
        $label = $this->optionalString($catalog, 'label');

        return $label !== null && Str::contains($label, 'Uge', ignoreCase: true);
    }

    private function algoliaHttp(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->withQueryParameters([
                'x-algolia-agent' => 'Algolia for vanilla JavaScript 3.21.1',
                'x-algolia-application-id' => self::ALGOLIA_APPLICATION_ID,
                'x-algolia-api-key' => self::ALGOLIA_API_KEY,
            ]);
    }

    private function productHttp(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->retry([500, 2000])
            ->withHeaders([
                'X-Device' => 'web',
                'X-Timezone' => 'Copenhagen/Europe',
                'X-Locale' => 'da',
            ]);
    }

    private function catalogHttp(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->retry([200, 500]);
    }

    private function tjekHttp(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([200, 500]);
    }

    private function sleepBetweenDetailRequests(): void
    {
        if (! $this->sleepBetweenDetailRequests) {
            return;
        }

        usleep(self::PRODUCT_DETAIL_DELAY_MICROSECONDS + random_int(0, self::PRODUCT_DETAIL_JITTER_MICROSECONDS));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function date(array $payload, string $key): ?CarbonImmutable
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || trim($value) === '') {
            throw new ScraperFetchException("REMA 1000 source is missing {$key}.");
        }

        return $value;
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
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ScraperFetchException('REMA 1000 payload could not be encoded.', previous: $exception);
        }
    }
}
