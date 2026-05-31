<?php

namespace App\Scrapers\Coop;

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

class CoopTjekScraper implements GrocerScraper
{
    private const TJEK_BASE_URL = 'https://squid-api.tjek.com';

    private const TJEK_API_URL = self::TJEK_BASE_URL.'/v2';

    private const OFFERS_PAGE_SIZE = 100;

    private const INCITO_API_KEY = 'TOv5ok';

    public function __construct(
        private readonly CoopBanner $banner,
        private readonly CoopTjekPaperParser $parser = new CoopTjekPaperParser,
    ) {}

    public function grocerKey(): string
    {
        return $this->banner->key;
    }

    /**
     * @return list<RawPaperPayload>
     */
    public function fetchPapers(?int $limit = null, ?callable $progress = null): array
    {
        $this->progress($progress, "Fetching {$this->banner->name} Tjek catalogs...");
        $catalogs = $this->fetchActiveCatalogs();
        $this->progress($progress, 'Found '.count($catalogs)." active {$this->banner->name} catalogs.");

        $weeklyCatalogs = array_values(array_filter($catalogs, fn (array $catalog): bool => $this->isWeeklyCatalog($catalog)));
        $this->progress($progress, 'Found '.count($weeklyCatalogs)." active {$this->banner->name} Uge catalogs eligible for publishing.");

        if ($weeklyCatalogs === []) {
            throw new ScraperFetchException("{$this->banner->name} found no active Uge catalogs.");
        }

        return array_map(function (array $catalog) use ($limit, $progress): RawPaperPayload {
            $catalogId = $this->requiredString($catalog, 'id');

            $this->progress($progress, "Fetching {$this->banner->name} offers for catalog {$catalogId}...");
            $offers = $this->fetchCatalogOffers($catalogId, $limit);
            $this->progress($progress, 'Fetched '.count($offers)." {$this->banner->name} offers for catalog {$catalogId}.".($limit ? ' after limit' : ''));

            $incitoOffers = $this->fetchIncitoOffers($catalogId);
            $this->progress($progress, 'Fetched '.count($incitoOffers)." {$this->banner->name} Incito offer enrichments for catalog {$catalogId}.");

            $offers = $this->enrichOffers($offers, $incitoOffers);

            return $this->rawPayload($catalog, $offers);
        }, $weeklyCatalogs);
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
                'dealer_id' => $this->banner->dealerId,
                'order_by' => '-publication_date',
                'offset' => 0,
                'limit' => 24,
                'types' => 'paged,incito',
            ])
            ->throw()
            ->json();

        if (! is_array($catalogs)) {
            throw new ScraperFetchException("{$this->banner->name} catalog response was not an array.");
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
     * @return list<array<string, mixed>>
     */
    private function fetchCatalogOffers(string $catalogId, ?int $limit): array
    {
        $offers = [];
        $offset = 0;

        do {
            $pageLimit = $limit === null ? self::OFFERS_PAGE_SIZE : min(self::OFFERS_PAGE_SIZE, $limit - count($offers));

            if ($pageLimit <= 0) {
                break;
            }

            $page = $this->tjekHttp()
                ->get(self::TJEK_API_URL.'/offers', [
                    'catalog_id' => $catalogId,
                    'offset' => $offset,
                    'limit' => $pageLimit,
                ])
                ->throw()
                ->json();

            if (! is_array($page)) {
                throw new ScraperFetchException("{$this->banner->name} offers response for catalog {$catalogId} was not an array.");
            }

            $pageOffers = array_values(array_filter($page, 'is_array'));
            $offers = array_merge($offers, $pageOffers);
            $offset += count($pageOffers);
        } while (count($pageOffers) === $pageLimit && ($limit === null || count($offers) < $limit));

        if ($offers === []) {
            throw new ScraperFetchException("{$this->banner->name} catalog {$catalogId} returned no offers.");
        }

        return $offers;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchIncitoOffers(string $catalogId): array
    {
        $payload = Http::acceptJson()
            ->withHeaders([
                'x-api-key' => self::INCITO_API_KEY,
                'Origin' => parse_url($this->banner->sourceUrl, PHP_URL_SCHEME).'://'.parse_url($this->banner->sourceUrl, PHP_URL_HOST),
                'Referer' => $this->banner->sourceUrl,
            ])
            ->timeout(30)
            ->connectTimeout(5)
            ->retry([200, 500])
            ->post(self::TJEK_BASE_URL.'/v4/rpc/generate_incito_from_publication', [
                'id' => $catalogId,
                'device_category' => 'desktop',
                'pointer' => 'fine',
                'orientation' => 'horizontal',
                'pixel_ratio' => 1,
                'max_width' => 1011,
                'versions_supported' => ['1.0.0'],
                'locale_code' => 'da-DK',
                'time' => now()->toJSON(),
                'feature_labels' => [['key' => 'none', 'value' => 1]],
                'enable_lazy_loading' => false,
            ])
            ->throw()
            ->json();

        if (! is_array($payload)) {
            throw new ScraperFetchException("{$this->banner->name} Incito response for catalog {$catalogId} was not an object.");
        }

        $offers = [];
        $this->collectIncitoOffers($payload['root_view'] ?? [], $offers);

        return $offers;
    }

    /**
     * @param  array<string, mixed>|list<mixed>|mixed  $node
     * @param  array<string, array<string, mixed>>  $offers
     */
    private function collectIncitoOffers(mixed $node, array &$offers): void
    {
        if (! is_array($node)) {
            return;
        }

        $id = $this->optionalString($node, 'id');
        $offer = $node['meta']['tjek.offer.v1'] ?? null;

        if (($node['role'] ?? null) === 'offer' && $id !== null && is_array($offer)) {
            $offers[$id] = $offer;
        }

        $childViews = $node['child_views'] ?? [];

        if (! is_array($childViews)) {
            return;
        }

        foreach ($childViews as $childView) {
            $this->collectIncitoOffers($childView, $offers);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @param  array<string, array<string, mixed>>  $incitoOffers
     * @return list<array<string, mixed>>
     */
    private function enrichOffers(array $offers, array $incitoOffers): array
    {
        return array_map(function (array $offer) use ($incitoOffers): array {
            $catalogViewId = $this->optionalString($offer, 'catalog_view_id');

            if ($catalogViewId === null || ! isset($incitoOffers[$catalogViewId])) {
                return $offer;
            }

            $incitoOffer = $incitoOffers[$catalogViewId];
            $products = Arr::get($incitoOffer, 'products');

            if (! is_array($products)) {
                return $offer;
            }

            return [
                ...$offer,
                '_incito_enrichment' => array_filter([
                    'offer_id' => $catalogViewId,
                    'title' => $this->optionalString($incitoOffer, 'title'),
                    'description' => $this->optionalString($incitoOffer, 'description'),
                    'quantity' => $this->optionalString($incitoOffer, 'quantity'),
                    'products' => $this->normalizeIncitoProducts($products),
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }, $offers);
    }

    /**
     * @param  list<mixed>  $products
     * @return list<array<string, mixed>>
     */
    private function normalizeIncitoProducts(array $products): array
    {
        return array_values(array_filter(array_map(function (mixed $product): ?array {
            if (! is_array($product)) {
                return null;
            }

            $id = $this->optionalString($product, 'id');
            $title = $this->optionalString($product, 'title');

            if ($id === null || $title === null) {
                return null;
            }

            return array_filter([
                'id' => $id,
                'title' => $title,
                'image' => $this->optionalString($product, 'image'),
                'ids' => is_array($product['ids'] ?? null) ? $product['ids'] : null,
            ], static fn (mixed $value): bool => $value !== null);
        }, $products)));
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
                'source_strategy' => 'tjek_coop_weekly_catalog_offers',
                'fetched_offer_count' => count($offers),
                'offer_count_mismatch' => is_numeric(Arr::get($catalog, 'offer_count')) ? (int) Arr::get($catalog, 'offer_count') - count($offers) : null,
                'source_url' => $this->banner->sourceUrl,
                'incito_enriched_offer_count' => count(array_filter($offers, static fn (array $offer): bool => isset($offer['_incito_enrichment']))),
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

    private function tjekHttp(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([200, 500]);
    }

    private function progress(?callable $progress, string $message): void
    {
        if ($progress !== null) {
            $progress($message);
        }
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
            throw new ScraperFetchException("{$this->banner->name} source is missing {$key}.");
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
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ScraperFetchException("{$this->banner->name} payload could not be encoded.", previous: $exception);
        }
    }
}
