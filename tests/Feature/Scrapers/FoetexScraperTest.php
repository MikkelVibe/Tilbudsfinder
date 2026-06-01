<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\Foetex\FoetexScraper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FoetexScraperTest extends TestCase
{
    public function test_it_fetches_weekly_foetex_catalogs_with_tjek_offers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('weekly-paper', 'Uge 23/24', 12),
                $this->catalog('summer-beauty', 'Sommerskøn', 12),
                $this->catalog('expired-paper', 'Uge 22', 12, '2026-05-20T22:00:00+0000', '2026-05-27T21:59:59+0000'),
            ]),
            'squid-api.tjek.com/v2/offers?catalog_id=weekly-paper&offset=0&limit=100' => Http::response(array_map(fn (int $number): array => $this->offer($number), range(1, 12))),
            'drp4o45g5t-dsn.algolia.net/1/indexes/prod_FOETEX_PRODUCTS/query' => Http::response([
                'nbHits' => 1,
                'nbPages' => 1,
                'hits' => [$this->sallingProduct('Product 1', '5700000000002', 10)],
            ]),
        ]);

        $payloads = (new FoetexScraper)->fetchPapers();

        $this->assertCount(1, $payloads);
        $this->assertSame('weekly-paper', $payloads[0]->sourceExternalId);
        $this->assertSame('Uge 23/24', $payloads[0]->title);

        $payload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('tjek_weekly_catalog_offers', $payload['catalog']['source_strategy']);
        $this->assertSame(1, $payload['catalog']['salling_enriched_offer_count']);
        $this->assertSame(12, $payload['catalog']['fetched_offer_count']);
        $this->assertSame(0, $payload['catalog']['offer_count_mismatch']);
        $this->assertSame('Product 1', $payload['offers'][0]['heading']);
        $this->assertSame(['5700000000002'], $payload['offers'][0]['_salling_enrichment']['eans']);
        $this->assertArrayNotHasKey('_salling_enrichment', $payload['offers'][1]);
    }

    public function test_it_fails_when_no_weekly_catalog_is_active(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('summer-beauty', 'Sommerskøn', 12),
            ]),
        ]);

        $this->expectException(ScraperFetchException::class);
        $this->expectExceptionMessage('føtex found no active Uge catalogs.');

        (new FoetexScraper)->fetchPapers();
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(string $id, string $label, int $offerCount, string $runFrom = '2026-05-28T22:00:00+0000', string $runTill = '2026-06-11T21:59:59+0000'): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'run_from' => $runFrom,
            'run_till' => $runTill,
            'offer_count' => $offerCount,
            'page_count' => 93,
            'dealer_id' => 'bdf5A',
            'dealer' => ['name' => 'føtex'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(int $number): array
    {
        return [
            'id' => "offer-{$number}",
            'heading' => "Product {$number}",
            'description' => '200 g. Pr. kg 50,00.',
            'catalog_page' => $number,
            'pricing' => ['price' => 10, 'currency' => 'DKK'],
            'quantity' => [
                'unit' => ['symbol' => 'g'],
                'size' => ['from' => 200, 'to' => 200],
                'pieces' => ['from' => 1, 'to' => 1],
            ],
            'images' => ['zoom' => "https://images.example/foetex/{$number}.webp"],
            'catalog_id' => 'weekly-paper',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sallingProduct(string $name, string $ean, int $price): array
    {
        return [
            'objectID' => 'salling-product-1',
            'name' => $name,
            'brand' => 'Salling',
            'active_gtin' => $ean,
            'gtins' => [$ean],
            'sales_price' => $price,
            'net_content' => '200',
            'net_content_unit_of_measure_display' => 'g',
        ];
    }
}
