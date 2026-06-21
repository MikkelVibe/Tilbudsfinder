<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\Nemlig\NemligScraper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NemligScraperTest extends TestCase
{
    public function test_it_fetches_campaign_products_from_offer_groups(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'www.nemlig.com/tilbud*' => Http::response($this->offersPage()),
            'www.nemlig.com/webapi/Token' => Http::response(['access_token' => 'test-token']),
            'www.nemlig.com/webapi/AAAAAAAA-oLJ90N-_/2026060208-60-600/1/0/Products/GetByProductGroupId?productGroupId=group-1&pageIndex=0&pagesize=5' => Http::response($this->productGroupResponse(range(1, 5))),
            'www.nemlig.com/webapi/AAAAAAAA/2026060208-60-600/1/0/Products/Get?id=*' => Http::response($this->productDetail()),
        ]);

        $scraper = new NemligScraper;
        $payloads = $scraper->fetchPapers($scraper->discoverPapers(), limit: 5);

        $this->assertCount(1, $payloads);
        $this->assertSame('nemlig-20260531220000-20260607215959', $payloads[0]->sourceExternalId);

        $payload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('nemlig_product_groups', $payload['catalog']['source_strategy']);
        $this->assertSame(5, $payload['catalog']['fetched_offer_count']);
        $this->assertSame('2026-05-31T22:00:00+00:00', $payload['catalog']['run_from']);
        $this->assertSame('2026-06-07T21:59:59+00:00', $payload['catalog']['run_till']);
        $this->assertSame('Nemlig Product 1', $payload['offers'][0]['Name']);
        $this->assertSame('Skarp pris', $payload['offers'][0]['_nemlig_group']['Heading']);
        $this->assertSame('12', $payload['offers'][0]['_nemlig_detail']['Declarations']['EnergyKcal']);
        $this->assertSame('VKN-1', $payload['offers'][0]['_nemlig_detail']['VkNumber']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.nemlig.com/webapi/AAAAAAAA-oLJ90N-_/2026060208-60-600/1/0/Products/GetByProductGroupId?productGroupId=group-1&pageIndex=0&pagesize=5'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://www.nemlig.com/webapi/AAAAAAAA/2026060208-60-600/1/0/Products/Get?id=')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_it_returns_one_payload_per_visible_campaign_interval(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.nemlig.com/tilbud*' => Http::response($this->offersPage()),
            'www.nemlig.com/webapi/Token' => Http::response(['access_token' => 'test-token']),
            'www.nemlig.com/webapi/AAAAAAAA-oLJ90N-_/2026060208-60-600/1/0/Products/GetByProductGroupId?productGroupId=group-1&pageIndex=0&pagesize=200' => Http::response([
                'Products' => [
                    $this->product(1),
                    $this->product(2, '2026-06-07T22:00:00Z', '2026-06-14T21:59:59Z'),
                    $this->product(3, showCampaignInterval: false),
                ],
            ]),
            'www.nemlig.com/webapi/AAAAAAAA/2026060208-60-600/1/0/Products/Get?id=*' => Http::response($this->productDetail()),
        ]);

        $payloads = (new NemligScraper)->fetchPapers((new NemligScraper)->discoverPapers());

        $this->assertCount(2, $payloads);
        $this->assertSame([
            'nemlig-20260531220000-20260607215959',
            'nemlig-20260607220000-20260614215959',
        ], array_map(fn ($payload): string => $payload->sourceExternalId, $payloads));

        $firstPayload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);
        $secondPayload = json_decode($payloads[1]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['Nemlig Product 1'], array_column($firstPayload['offers'], 'Name'));
        $this->assertSame(['Nemlig Product 2'], array_column($secondPayload['offers'], 'Name'));
        $this->assertSame(1, $firstPayload['catalog']['skipped_hidden_interval_offer_count']);
    }

    public function test_it_skips_visible_campaign_products_outside_food_and_personal_care_scope(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.nemlig.com/tilbud*' => Http::response($this->offersPage()),
            'www.nemlig.com/webapi/Token' => Http::response(['access_token' => 'test-token']),
            'www.nemlig.com/webapi/AAAAAAAA-oLJ90N-_/2026060208-60-600/1/0/Products/GetByProductGroupId?productGroupId=group-1&pageIndex=0&pagesize=200' => Http::response([
                'Products' => [
                    $this->product(1),
                    $this->product(2, category: 'Husholdning', subcategory: 'Køkkenudstyr - redskaber'),
                    $this->product(3, category: 'Blomster & tilbehør', subcategory: 'Potteplanter'),
                    $this->product(4, category: 'Kiosk', subcategory: 'Tobakstilbehør'),
                    $this->product(5, category: 'Pleje', subcategory: 'Lingeri'),
                ],
            ]),
            'www.nemlig.com/webapi/AAAAAAAA/2026060208-60-600/1/0/Products/Get?id=*' => Http::response($this->productDetail()),
        ]);

        $payloads = (new NemligScraper)->fetchPapers((new NemligScraper)->discoverPapers());
        $payload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $payloads);
        $this->assertSame(['Nemlig Product 1'], array_column($payload['offers'], 'Name'));
        $this->assertSame(4, $payload['catalog']['skipped_irrelevant_offer_count']);
    }

    public function test_it_fails_when_no_visible_campaign_products_have_valid_intervals(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.nemlig.com/tilbud*' => Http::response($this->offersPage()),
            'www.nemlig.com/webapi/Token' => Http::response(['access_token' => 'test-token']),
            'www.nemlig.com/webapi/AAAAAAAA-oLJ90N-_/2026060208-60-600/1/0/Products/GetByProductGroupId?productGroupId=group-1&pageIndex=0&pagesize=200' => Http::response([
                'Products' => [
                    $this->product(1, showCampaignInterval: false),
                    $this->product(2, end: null),
                ],
            ]),
        ]);

        $this->expectException(ScraperFetchException::class);
        $this->expectExceptionMessage('Nemlig returned no visible campaign products with valid intervals.');

        (new NemligScraper)->fetchPapers((new NemligScraper)->discoverPapers());
    }

    public function test_it_fails_when_offers_page_has_no_product_groups(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'www.nemlig.com/tilbud*' => Http::response([
                'Settings' => $this->settings(),
                'content' => [],
            ]),
        ]);

        $this->expectException(ScraperFetchException::class);
        $this->expectExceptionMessage('Nemlig offers page returned no product groups.');

        (new NemligScraper)->discoverPapers();
    }

    /**
     * @return array<string, mixed>
     */
    private function offersPage(): array
    {
        return [
            'Settings' => $this->settings(),
            'content' => [
                ['TemplateName' => 'ribbon'],
                ['Heading' => 'Sponsoreret', 'ProductGroupId' => 'sponsored-group', 'TotalProducts' => 7],
                ['Heading' => 'Skarp pris', 'ProductGroupId' => 'group-1', 'TotalProducts' => 56],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'TimeslotUtc' => '2026060208-60-600',
            'DeliveryZoneId' => 1,
            'ProductsImportedTimestamp' => 'AAAAAAAA',
            'CombinedProductsAndSitecoreTimestamp' => 'AAAAAAAA-oLJ90N-_',
            'BuildVersion' => 'b1.0.9606.11183',
        ];
    }

    /**
     * @param  list<int>  $numbers
     * @return array<string, mixed>
     */
    private function productGroupResponse(array $numbers): array
    {
        return [
            'Products' => array_map(fn (int $number): array => $this->product($number), $numbers),
            'ProductGroupId' => 'group-1',
            'Start' => 0,
            'NumFound' => count($numbers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(
        int $number,
        string $start = '2026-05-31T22:00:00Z',
        ?string $end = '2026-06-07T21:59:59Z',
        bool $showCampaignInterval = true,
        string $category = 'Grønt',
        string $subcategory = 'Agurk / Tomat',
    ): array {
        return [
            'Id' => (string) (5070000 + $number),
            'Name' => "Nemlig Product {$number}",
            'Category' => $category,
            'SubCategory' => $subcategory,
            'PrimaryImage' => "https://www.nemlig.com/images/{$number}.jpg",
            'UnitPrice' => '40,00 kr./Kg.',
            'UnitPriceCalc' => 40,
            'UnitPriceLabel' => 'kr./Kg.',
            'Description' => '1,5 kg / Holland / Klasse 1',
            'Price' => 110,
            'Campaign' => [
                'DiscountSavings' => 50,
                'MaxQuantity' => 0,
                'CampaignPrice' => 60,
                'CampaignUnitPrice' => 40,
                'Type' => 'ProductCampaignDiscount',
                'Code' => 'US',
                'IntervalStart' => $start,
                'IntervalEnd' => $end,
                'ShowCampaignInterval' => $showCampaignInterval,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productDetail(): array
    {
        return [
            'Declarations' => [
                'ShowDeclarations' => true,
                'EnergyKcal' => '12',
                'EnergyKj' => '51',
                'NutritionalContentProtein' => '0,7',
                'NutritionalContentFat' => '0,1',
                'NutritionalContentCarbohydrate' => '2,1',
                'SaturatedFattyAcid' => '0',
                'Sugar' => '1,9',
                'Salt' => '0,01',
                'DietaryFiber' => '1,2',
            ],
            'Attributes' => [[
                'Key' => 'Oprindelsesland',
                'Value' => ['Danmark'],
            ]],
            'Traceability' => ['LotNumber' => 'ABC'],
            'TechnicalDescription' => 'Detail text',
            'OriginCodeDescription' => 'Danmark',
            'VkNumber' => 'VKN-1',
            'Text' => 'Product text',
        ];
    }
}
