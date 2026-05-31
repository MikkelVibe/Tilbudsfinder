<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Coop\CoopBanner;
use App\Scrapers\Coop\CoopTjekScraper;
use App\Scrapers\Exceptions\ScraperFetchException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoopTjekScraperTest extends TestCase
{
    public function test_it_fetches_only_active_weekly_coop_catalogs_with_tjek_offers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('wine-paper', 'Vin katalog', 61),
                $this->catalog('weekly-paper', 'Uge 23', 12),
                $this->catalog('expired-paper', 'Uge 22', 12, '2026-05-20T22:00:00+0000', '2026-05-27T21:59:59+0000'),
            ]),
            'squid-api.tjek.com/v2/offers?catalog_id=weekly-paper&offset=0&limit=100' => Http::response(array_map(fn (int $number): array => $this->offer($number), range(1, 12))),
            'squid-api.tjek.com/v4/rpc/generate_incito_from_publication*' => Http::response($this->incitoPayload()),
        ]);

        $payloads = (new CoopTjekScraper(CoopBanner::kvickly()))->fetchPapers();

        $this->assertCount(1, $payloads);
        $this->assertSame('weekly-paper', $payloads[0]->sourceExternalId);
        $this->assertSame('Uge 23', $payloads[0]->title);

        $payload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('tjek_coop_weekly_catalog_offers', $payload['catalog']['source_strategy']);
        $this->assertSame('https://kvickly.coop.dk/tilbudsavis/', $payload['catalog']['source_url']);
        $this->assertSame(12, $payload['catalog']['fetched_offer_count']);
        $this->assertSame(0, $payload['catalog']['offer_count_mismatch']);
        $this->assertSame(1, $payload['catalog']['incito_enriched_offer_count']);
        $this->assertSame('COOP Product 1', $payload['offers'][0]['heading']);
        $this->assertSame('incito-offer-1', $payload['offers'][0]['_incito_enrichment']['offer_id']);
        $this->assertSame('5700000000011', $payload['offers'][0]['_incito_enrichment']['products'][0]['id']);
        $this->assertArrayNotHasKey('_incito_enrichment', $payload['offers'][1]);
    }

    public function test_it_supports_all_physical_coop_banner_keys(): void
    {
        $this->assertSame('kvickly', (new CoopTjekScraper(CoopBanner::kvickly()))->grocerKey());
        $this->assertSame('superbrugsen', (new CoopTjekScraper(CoopBanner::superbrugsen()))->grocerKey());
        $this->assertSame('daglibrugsen', (new CoopTjekScraper(CoopBanner::daglibrugsen()))->grocerKey());
        $this->assertSame('365discount', (new CoopTjekScraper(CoopBanner::discount365()))->grocerKey());
    }

    public function test_it_fails_when_no_weekly_catalog_is_active(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('wine-paper', 'Vin katalog', 61),
            ]),
        ]);

        $this->expectException(ScraperFetchException::class);
        $this->expectExceptionMessage('SuperBrugsen found no active Uge catalogs.');

        (new CoopTjekScraper(CoopBanner::superbrugsen()))->fetchPapers();
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
            'page_count' => 56,
            'dealer_id' => 'c1edq',
            'dealer' => ['name' => 'Kvickly'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(int $number): array
    {
        return [
            'id' => "coop-offer-{$number}",
            'heading' => "COOP Product {$number}",
            'description' => '200 g. Kg-pris 50,00. Frit valg. 1 stk.',
            'catalog_page' => $number,
            'catalog_view_id' => $number === 1 ? 'incito-offer-1' : "incito-offer-{$number}",
            'pricing' => ['price' => 10, 'currency' => 'DKK'],
            'quantity' => [
                'unit' => ['symbol' => 'g'],
                'size' => ['from' => 200, 'to' => 200],
                'pieces' => ['from' => 1, 'to' => 1],
            ],
            'images' => ['zoom' => "https://images.example/coop/{$number}.webp"],
            'catalog_id' => 'weekly-paper',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incitoPayload(): array
    {
        return [
            'root_view' => [
                'child_views' => [[
                    'role' => 'offer',
                    'id' => 'incito-offer-1',
                    'meta' => [
                        'tjek.offer.v1' => [
                            'title' => 'COOP Product 1',
                            'description' => '200 g. Kg-pris 50,00. Frit valg. 1 stk.',
                            'quantity' => '1 stk.',
                            'products' => [[
                                'id' => '5700000000011',
                                'title' => 'COOP PRODUCT VARIANT 200 G',
                                'image' => 'https://images.example/incito/product.webp',
                                'ids' => [['type' => 'category', 'value' => '1', 'provider' => 'self']],
                            ]],
                        ],
                    ],
                ]],
            ],
        ];
    }
}
