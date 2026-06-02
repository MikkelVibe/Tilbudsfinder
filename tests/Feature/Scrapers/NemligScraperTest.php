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
        $this->assertSame('nemlig-AAAAAAAA-oLJ90N-_-2026060208-60-600', $payloads[0]->sourceExternalId);

        $payload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('nemlig_product_groups', $payload['catalog']['source_strategy']);
        $this->assertSame(5, $payload['catalog']['fetched_offer_count']);
        $this->assertSame('Nemlig Product 1', $payload['offers'][0]['Name']);
        $this->assertSame('Skarp pris', $payload['offers'][0]['_nemlig_group']['Heading']);
        $this->assertSame('12', $payload['offers'][0]['_nemlig_detail']['Declarations']['EnergyKcal']);
        $this->assertSame('VKN-1', $payload['offers'][0]['_nemlig_detail']['VkNumber']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://www.nemlig.com/webapi/AAAAAAAA-oLJ90N-_/2026060208-60-600/1/0/Products/GetByProductGroupId?productGroupId=group-1&pageIndex=0&pagesize=5'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://www.nemlig.com/webapi/AAAAAAAA/2026060208-60-600/1/0/Products/Get?id=')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
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
    private function product(int $number): array
    {
        return [
            'Id' => (string) (5070000 + $number),
            'Name' => "Nemlig Product {$number}",
            'Category' => 'Grønt',
            'SubCategory' => 'Agurk / Tomat',
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
                'IntervalStart' => '2026-05-31T22:00:00Z',
                'IntervalEnd' => '2026-06-07T21:59:59Z',
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
