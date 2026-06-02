<?php

namespace App\Scrapers\Bilka;

use App\Imports\DTO\ParsedPaperInput;
use App\Scrapers\DTO\PaperCandidate;
use App\Scrapers\DTO\RawPaperPayload;
use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\GrocerScraper;
use App\Scrapers\Support\KnownPaperPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;

class BilkaScraper implements GrocerScraper
{
    private const TJEK_API_URL = 'https://squid-api.tjek.com/v2';

    private const TJEK_DEALER_ID = '93f13';

    private const BILKATOGO_ALGOLIA_APP_ID = 'F9VBJLR1BK';

    private const BILKATOGO_ALGOLIA_API_KEY = '1deaf41c87e729779f7695c00f190cc9';

    private const BILKATOGO_INDEX = 'prod_BILKATOGO_PRODUCTS';

    private const BILKATOGO_STORE_ID = '1653';

    private const BILKATOGO_PAGE_SIZE = 1000;

    /**
     * @var list<string>
     */
    private const FOOD_CATEGORIES = [
        'Frugt & grønt',
        'Kød & fisk',
        'Mejeri & køl',
        'Drikkevarer',
        'Brød & kager',
        'Kolonial',
        'Mad fra hele verden',
        'Slik & snacks',
        'Frost',
    ];

    public function __construct(
        private readonly BilkaPaperParser $parser = new BilkaPaperParser,
    ) {}

    public function grocerKey(): string
    {
        return 'bilka';
    }

    /**
     * @return list<RawPaperPayload>
     */
    public function discoverPapers(?callable $progress = null): array
    {
        $this->progress($progress, 'Fetching Bilka Tjek catalogs...');
        $catalogs = $this->fetchActiveCatalogs();
        $this->progress($progress, 'Found '.count($catalogs).' active Bilka catalogs.');

        $foodCatalogs = array_values(array_filter($catalogs, fn (array $catalog): bool => $this->isFoodWeeklyCatalog($catalog)));
        $this->progress($progress, 'Found '.count($foodCatalogs).' active Bilka Food Uge catalogs eligible for publishing.');

        if ($foodCatalogs === []) {
            throw new ScraperFetchException('Bilka found no active Food Uge catalogs.');
        }

        return array_map(fn (array $catalog): PaperCandidate => $this->candidate($catalog), $foodCatalogs);
    }

    /**
     * @param  list<PaperCandidate>  $candidates
     * @param  array<string, array{exists: bool, title?: ?string, active_from?: ?string, active_until?: ?string}>  $knownPapers
     * @return list<RawPaperPayload>
     */
    public function fetchPapers(array $candidates, array $knownPapers = [], ?int $limit = null, ?callable $progress = null): array
    {
        return array_map(function (PaperCandidate $candidate) use ($knownPapers, $limit, $progress): RawPaperPayload {
            $knownPaper = $knownPapers[$candidate->sourceExternalId] ?? null;

            if (($knownPaper['exists'] ?? false) === true) {
                $this->progress($progress, "Skipping already imported Bilka catalog {$candidate->sourceExternalId}.");

                return KnownPaperPayload::make($this->grocerKey(), $candidate, $knownPaper);
            }

            $this->progress($progress, "Fetching BilkaToGo food leaflet products for catalog {$candidate->sourceExternalId}...");
            $offers = $this->fetchBilkaToGoFoodLeafletProducts($limit);
            $this->progress($progress, 'Fetched '.count($offers).' BilkaToGo food leaflet products.'.($limit ? ' after limit' : ''));

            return $this->rawPayload($candidate->sourcePayload, $offers);
        }, $candidates);
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
            throw new ScraperFetchException('Bilka catalog response was not an array.');
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
    private function fetchBilkaToGoFoodLeafletProducts(?int $limit): array
    {
        $offers = [];
        $seenIds = [];

        foreach (self::FOOD_CATEGORIES as $category) {
            $page = 0;

            do {
                $pageLimit = $limit === null ? self::BILKATOGO_PAGE_SIZE : min(self::BILKATOGO_PAGE_SIZE, $limit - count($offers));

                if ($pageLimit <= 0) {
                    break 2;
                }

                $response = $this->bilkaToGoHttp()
                    ->post('https://'.mb_strtolower(self::BILKATOGO_ALGOLIA_APP_ID).'-dsn.algolia.net/1/indexes/'.self::BILKATOGO_INDEX.'/query', [
                        'query' => '*',
                        'filters' => $this->bilkaToGoLeafletFilter($category),
                        'attributesToRetrieve' => $this->bilkaToGoAttributes(),
                        'hitsPerPage' => $pageLimit,
                        'page' => $page,
                    ])
                    ->throw()
                    ->json();

                if (! is_array($response)) {
                    throw new ScraperFetchException("BilkaToGo leaflet response for category {$category} was not an object.");
                }

                $hits = array_values(array_filter(Arr::get($response, 'hits', []), 'is_array'));

                foreach ($hits as $hit) {
                    $id = $this->optionalString($hit, 'objectID');

                    if ($id === null || isset($seenIds[$id])) {
                        continue;
                    }

                    $seenIds[$id] = true;
                    $offers[] = $hit;

                    if ($limit !== null && count($offers) >= $limit) {
                        break 3;
                    }
                }

                $page++;
                $nbPages = is_numeric(Arr::get($response, 'nbPages')) ? (int) Arr::get($response, 'nbPages') : $page;
            } while ($page < $nbPages && $hits !== []);
        }

        if ($offers === []) {
            throw new ScraperFetchException('BilkaToGo food leaflet returned no products.');
        }

        return $offers;
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function candidate(array $catalog): PaperCandidate
    {
        return new PaperCandidate(
            sourceExternalId: $this->requiredString($catalog, 'id'),
            title: $this->optionalString($catalog, 'label'),
            sourcePayload: $catalog,
        );
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
                'source_strategy' => 'bilkatogo_leaflet_food_products_with_tjek_dates',
                'bilkatogo_store_id' => self::BILKATOGO_STORE_ID,
                'food_categories' => self::FOOD_CATEGORIES,
                'fetched_offer_count' => count($offers),
                'offer_count_mismatch' => is_numeric(Arr::get($catalog, 'offer_count')) ? (int) Arr::get($catalog, 'offer_count') - count($offers) : null,
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
    private function isFoodWeeklyCatalog(array $catalog): bool
    {
        $label = $this->optionalString($catalog, 'label');

        return $label !== null
            && Str::contains($label, 'Uge', ignoreCase: true)
            && preg_match('/\bFood\b/iu', $label) === 1;
    }

    private function tjekHttp(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(20)
            ->connectTimeout(5)
            ->retry([200, 500]);
    }

    private function bilkaToGoHttp(): PendingRequest
    {
        return Http::acceptJson()
            ->withHeaders([
                'X-Algolia-Api-Key' => self::BILKATOGO_ALGOLIA_API_KEY,
                'X-Algolia-Application-Id' => self::BILKATOGO_ALGOLIA_APP_ID,
            ])
            ->timeout(30)
            ->connectTimeout(5)
            ->retry([200, 500]);
    }

    private function bilkaToGoLeafletFilter(string $category): string
    {
        return 'consumerFacingHierarchy.lvl0:"'.$category.'"'
            .' AND inStockStore:'.self::BILKATOGO_STORE_ID
            .' AND isInAssortmentIn:'.self::BILKATOGO_STORE_ID
            .' AND nonsearchable:false'
            .' AND isInCurrentLeaflet:true';
    }

    /**
     * @return list<string>
     */
    private function bilkaToGoAttributes(): array
    {
        return [
            'objectID',
            'article',
            'brand',
            'description',
            'netcontent',
            'imageGUIDs',
            'images',
            'infos',
            'isInCurrentLeaflet',
            'isInOffer',
            'name',
            'storeData',
            'cpOffer',
            'cpOfferAmount',
            'cpOfferId',
            'cpOfferPrice',
            'cpOfferFromDate',
            'cpOfferToDate',
            'subBrand',
            'units',
            'unitsOfMeasure',
            'consumerFacingHierarchy',
            'nonsearchable',
        ];
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
            throw new ScraperFetchException("Bilka source is missing {$key}.");
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
            throw new ScraperFetchException('Bilka payload could not be encoded.', previous: $exception);
        }
    }
}
