<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Bilka\BilkaScraper;
use App\Scrapers\Exceptions\ScraperFetchException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BilkaScraperTest extends TestCase
{
    public function test_it_fetches_only_food_weekly_bilka_catalogs_with_bilkatogo_leaflet_products(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('nonfood-paper', 'Bilka Nonfood Uge 23 2026 - Elektronik, Bolig, Have & Tekstil', 12),
                $this->catalog('food-paper', 'Bilka Food Uge 23 2026 - Fødevarer & Personlig Pleje', 12),
                $this->catalog('outdoor-paper', 'Bilka Outdoor 2026', 12),
                $this->catalog('expired-food-paper', 'Bilka Food Uge 22 2026 - Fødevarer', 12, '2026-05-20T22:00:00+0000', '2026-05-27T21:59:59+0000'),
            ]),
            'f9vbjlr1bk-dsn.algolia.net/1/indexes/prod_BILKATOGO_PRODUCTS/query' => Http::response([
                'nbHits' => 12,
                'nbPages' => 1,
                'hits' => array_map(fn (int $number): array => $this->bilkaToGoProduct($number), range(1, 12)),
            ]),
        ]);

        $payloads = (new BilkaScraper)->fetchPapers();

        $this->assertCount(1, $payloads);
        $this->assertSame('food-paper', $payloads[0]->sourceExternalId);
        $this->assertSame('Bilka Food Uge 23 2026 - Fødevarer & Personlig Pleje', $payloads[0]->title);

        $payload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('bilkatogo_leaflet_food_products_with_tjek_dates', $payload['catalog']['source_strategy']);
        $this->assertSame('1653', $payload['catalog']['bilkatogo_store_id']);
        $this->assertContains('Frugt & grønt', $payload['catalog']['food_categories']);
        $this->assertSame(12, $payload['catalog']['fetched_offer_count']);
        $this->assertSame(0, $payload['catalog']['offer_count_mismatch']);
        $this->assertSame('Product 1', $payload['offers'][0]['name']);
        $this->assertSame('5700000000001', $payload['offers'][0]['infos'][0]['items'][0]['value']);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'prod_BILKATOGO_PRODUCTS/query')
                && str_contains($request['filters'], 'consumerFacingHierarchy.lvl0:"Frugt & grønt"')
                && str_contains($request['filters'], 'inStockStore:1653')
                && str_contains($request['filters'], 'isInCurrentLeaflet:true');
        });
    }

    public function test_it_fails_when_no_food_weekly_catalog_is_active(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('nonfood-paper', 'Bilka Nonfood Uge 23 2026 - Elektronik, Bolig, Have & Tekstil', 12),
            ]),
        ]);

        $this->expectException(ScraperFetchException::class);
        $this->expectExceptionMessage('Bilka found no active Food Uge catalogs.');

        (new BilkaScraper)->fetchPapers();
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(string $id, string $label, int $offerCount, string $runFrom = '2026-05-28T22:00:00+0000', string $runTill = '2026-06-04T21:59:59+0000'): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'run_from' => $runFrom,
            'run_till' => $runTill,
            'offer_count' => $offerCount,
            'page_count' => 50,
            'dealer_id' => '93f13',
            'dealer' => ['name' => 'Bilka'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bilkaToGoProduct(int $number): array
    {
        return [
            'objectID' => "product-{$number}",
            'name' => "Product {$number}",
            'brand' => 'Salling',
            'description' => 'Salling product description',
            'netcontent' => '200 g',
            'units' => 200,
            'unitsOfMeasure' => 'g',
            'storeData' => [
                '1653' => [
                    'price' => 1000,
                    'unitsOfMeasureOfferPrice' => 5000,
                    'unitsOfMeasurePriceUnit' => 'Kg.',
                    'offerDescription' => 'Skarp pris',
                    'offerMax' => 0,
                ],
            ],
            'consumerFacingHierarchy' => [
                'lvl0' => ['Frugt & grønt'],
            ],
            'infos' => [
                [
                    'items' => [
                        ['title' => 'EAN', 'value' => '570000000000'.$number],
                    ],
                ],
            ],
        ];
    }
}
