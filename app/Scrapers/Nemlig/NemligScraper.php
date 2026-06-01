<?php

namespace App\Scrapers\Nemlig;

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

class NemligScraper implements GrocerScraper
{
    private const BASE_URL = 'https://www.nemlig.com';

    private const PAGE_SIZE = 200;

    /**
     * Nemlig exposes sponsored product groups on the offers page. Keep those out of MVP publishing.
     */
    private const EXCLUDED_GROUP_HEADINGS = [
        'sponsoreret',
    ];

    public function __construct(
        private readonly NemligPaperParser $parser = new NemligPaperParser,
    ) {}

    public function grocerKey(): string
    {
        return 'nemlig';
    }

    /**
     * @return list<RawPaperPayload>
     */
    public function fetchPapers(?int $limit = null, ?callable $progress = null): array
    {
        $this->progress($progress, 'Fetching Nemlig offers page...');

        $page = $this->fetchOffersPage();
        $settings = $this->arrayValue($page, 'Settings');
        $groups = $this->productGroups($page);

        if ($groups === []) {
            throw new ScraperFetchException('Nemlig offers page returned no product groups.');
        }

        $token = $this->fetchAccessToken();
        $products = [];
        $seenProductIds = [];
        $groupsFetched = [];

        foreach ($groups as $group) {
            $groupProducts = $this->fetchProductGroup($settings, $group, $token, $limit === null ? null : max(0, $limit - count($products)));

            foreach ($groupProducts as $product) {
                $productId = $this->optionalScalarString($product, 'Id');

                if ($productId !== null && isset($seenProductIds[$productId])) {
                    continue;
                }

                if ($productId !== null) {
                    $seenProductIds[$productId] = true;
                }

                $product['_nemlig_group'] = $group;
                $products[] = $product;
            }

            $groupsFetched[] = [
                'heading' => $this->requiredString($group, 'Heading'),
                'product_group_id' => $this->requiredString($group, 'ProductGroupId'),
                'total_products' => Arr::get($group, 'TotalProducts'),
                'fetched_products' => count($groupProducts),
            ];

            if ($limit !== null && count($products) >= $limit) {
                break;
            }
        }

        if ($products === []) {
            throw new ScraperFetchException('Nemlig returned no campaign products from offers groups.');
        }

        $this->progress($progress, 'Fetching Nemlig product details...');
        $products = $this->enrichProductDetails($settings, $products, $token);

        $this->progress($progress, 'Fetched '.count($products).' Nemlig campaign products from '.count($groupsFetched).' groups.');

        return [$this->rawPayload($settings, $groupsFetched, $products)];
    }

    public function parse(RawPaperPayload $payload): ParsedPaperInput
    {
        return $this->parser->parse($payload->rawPayload);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOffersPage(): array
    {
        $response = $this->http()
            ->get(self::BASE_URL.'/tilbud', [
                'GetAsJson' => 1,
                'd' => 1,
            ])
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new ScraperFetchException('Nemlig offers page response was not an object.');
        }

        return $response;
    }

    private function fetchAccessToken(): string
    {
        $response = $this->http()
            ->get(self::BASE_URL.'/webapi/Token')
            ->throw()
            ->json();

        if (! is_array($response)) {
            throw new ScraperFetchException('Nemlig token response was not an object.');
        }

        $token = Arr::get($response, 'access_token');

        if (! is_string($token) || trim($token) === '') {
            throw new ScraperFetchException('Nemlig token response did not contain an access token.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<array<string, mixed>>
     */
    private function productGroups(array $page): array
    {
        $content = $this->arrayValue($page, 'content');
        $groups = [];

        foreach ($content as $item) {
            if (! is_array($item) || ! is_string(Arr::get($item, 'ProductGroupId'))) {
                continue;
            }

            $heading = $this->optionalString($item, 'Heading');

            if ($heading === null || in_array(mb_strtolower($heading), self::EXCLUDED_GROUP_HEADINGS, true)) {
                continue;
            }

            if ((int) Arr::get($item, 'TotalProducts', 0) <= 0) {
                continue;
            }

            $groups[] = $item;
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $group
     * @return list<array<string, mixed>>
     */
    private function fetchProductGroup(array $settings, array $group, string $token, ?int $limit): array
    {
        if ($limit !== null && $limit <= 0) {
            return [];
        }

        $response = $this->http()
            ->withToken($token)
            ->get($this->productGroupUrl($settings), [
                'productGroupId' => $this->requiredString($group, 'ProductGroupId'),
                'pageIndex' => 0,
                'pagesize' => $limit === null ? self::PAGE_SIZE : min($limit, self::PAGE_SIZE),
            ])
            ->throw()
            ->json();

        $products = Arr::get($response, 'Products');

        if (! is_array($products)) {
            throw new ScraperFetchException('Nemlig product group response did not contain products.');
        }

        return array_values(array_filter(array_filter($products, 'is_array'), fn (array $product): bool => $this->hasCampaignPrice($product)));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function enrichProductDetails(array $settings, array $products, string $token): array
    {
        foreach ($products as $index => $product) {
            $productId = $this->optionalScalarString($product, 'Id');

            if ($productId === null) {
                continue;
            }

            $detail = $this->fetchProductDetail($settings, $productId, $token);

            if ($detail !== null) {
                $products[$index]['_nemlig_detail'] = $detail;
            }
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>|null
     */
    private function fetchProductDetail(array $settings, string $productId, string $token): ?array
    {
        $response = $this->http()
            ->withToken($token)
            ->get($this->productDetailUrl($settings), [
                'id' => $productId,
            ])
            ->throw()
            ->json();

        if (! is_array($response)) {
            return null;
        }

        return array_filter([
            'Declarations' => Arr::get($response, 'Declarations'),
            'Attributes' => Arr::get($response, 'Attributes'),
            'Traceability' => Arr::get($response, 'Traceability'),
            'TechnicalDescription' => Arr::get($response, 'TechnicalDescription'),
            'OriginCodeDescription' => Arr::get($response, 'OriginCodeDescription'),
            'VkNumber' => Arr::get($response, 'VkNumber'),
            'Text' => Arr::get($response, 'Text'),
        ], static fn (mixed $value): bool => $value !== null && $value !== [] && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function productGroupUrl(array $settings): string
    {
        return self::BASE_URL.'/webapi/'
            .$this->requiredString($settings, 'CombinedProductsAndSitecoreTimestamp').'/'
            .$this->requiredString($settings, 'TimeslotUtc').'/'
            .$this->requiredScalarString($settings, 'DeliveryZoneId')
            .'/0/Products/GetByProductGroupId';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function productDetailUrl(array $settings): string
    {
        return self::BASE_URL.'/webapi/'
            .$this->requiredString($settings, 'ProductsImportedTimestamp').'/'
            .$this->requiredString($settings, 'TimeslotUtc').'/'
            .$this->requiredScalarString($settings, 'DeliveryZoneId')
            .'/0/Products/Get';
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function hasCampaignPrice(array $product): bool
    {
        return is_numeric(Arr::get($product, 'Campaign.CampaignPrice'))
            && (float) Arr::get($product, 'Campaign.CampaignPrice') > 0;
    }

    /**
     * @param  list<array<string, mixed>>  $groupsFetched
     * @param  list<array<string, mixed>>  $products
     */
    private function rawPayload(array $settings, array $groupsFetched, array $products): RawPaperPayload
    {
        $campaignDates = $this->campaignDates($products);
        $activeFrom = $campaignDates['active_from'] ?? CarbonImmutable::now()->startOfDay();
        $activeUntil = $campaignDates['active_until'] ?? CarbonImmutable::now()->endOfDay();
        $sourceExternalId = 'nemlig-'.$activeFrom->format('Y-m-d').'-'.$activeUntil->format('Y-m-d');

        $rawPayload = $this->encode([
            'catalog' => [
                'id' => $sourceExternalId,
                'label' => 'Nemlig tilbud '.$activeFrom->format('Y-m-d').' - '.$activeUntil->format('Y-m-d'),
                'run_from' => $activeFrom->toIso8601String(),
                'run_till' => $activeUntil->toIso8601String(),
                'dealer' => ['name' => 'Nemlig'],
                'source_url' => self::BASE_URL.'/tilbud',
                'source_strategy' => 'nemlig_product_groups',
                'fetched_offer_count' => count($products),
                'groups' => $groupsFetched,
                'settings' => [
                    'timeslot_utc' => Arr::get($settings, 'TimeslotUtc'),
                    'delivery_zone_id' => Arr::get($settings, 'DeliveryZoneId'),
                    'combined_products_and_sitecore_timestamp' => Arr::get($settings, 'CombinedProductsAndSitecoreTimestamp'),
                    'build_version' => Arr::get($settings, 'BuildVersion'),
                ],
            ],
            'offers' => $products,
        ]);

        return new RawPaperPayload(
            sourceExternalId: $sourceExternalId,
            rawPayload: $rawPayload,
            title: 'Nemlig tilbud',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array{active_from?: CarbonImmutable, active_until?: CarbonImmutable}
     */
    private function campaignDates(array $products): array
    {
        $starts = [];
        $ends = [];

        foreach ($products as $product) {
            $start = $this->optionalString($product, 'Campaign.IntervalStart');
            $end = $this->optionalString($product, 'Campaign.IntervalEnd');

            if ($start !== null) {
                $starts[] = CarbonImmutable::parse($start);
            }

            if ($end !== null) {
                $ends[] = CarbonImmutable::parse($end);
            }
        }

        return array_filter([
            'active_from' => $starts === [] ? null : min($starts),
            'active_until' => $ends === [] ? null : max($ends),
        ]);
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->retry([200, 500])
            ->withHeaders([
                'accept-language' => 'en-US,en;q=0.9',
                'device-size' => 'desktop',
                'platform' => 'web',
                'referer' => self::BASE_URL.'/tilbud',
                'sec-fetch-dest' => 'empty',
                'sec-fetch-mode' => 'cors',
                'sec-fetch-site' => 'same-origin',
                'version' => '11.233.0',
                'x-correlation-id' => (string) Str::uuid(),
            ])
            ->withUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36');
    }

    private function progress(?callable $progress, string $message): void
    {
        if ($progress !== null) {
            $progress($message);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function arrayValue(array $payload, string $key): array
    {
        $value = Arr::get($payload, $key);

        if (! is_array($value)) {
            throw new ScraperFetchException("Nemlig source is missing {$key}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || trim($value) === '') {
            throw new ScraperFetchException("Nemlig source is missing {$key}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredScalarString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '') {
            throw new ScraperFetchException("Nemlig source is missing {$key}.");
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

        if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
            return (string) $value;
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
            throw new ScraperFetchException('Nemlig payload could not be encoded.', previous: $exception);
        }
    }
}
