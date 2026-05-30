<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\Rema1000\Rema1000AvisvareSource;
use App\Scrapers\Rema1000\Rema1000CatalogSource;
use App\Scrapers\Rema1000\Rema1000OfferGrouper;
use App\Scrapers\Rema1000\Rema1000PaperMapper;
use App\Scrapers\Rema1000\Rema1000ProductDetailSource;
use App\Scrapers\Rema1000\Rema1000Scraper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Rema1000ScraperTest extends TestCase
{
    public function test_it_fetches_algolia_products_and_groups_them_by_best_tjek_catalog_overlap(): void
    {
        CarbonImmutable::setTestNow('2026-05-29 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('weekly-paper', 'Uge 22', '2026-05-25T22:00:00+0000', '2026-05-30T21:59:59+0000'),
                $this->catalog('insert-paper', 'Uge 22 Indstik', '2026-05-27T22:00:00+0000', '2026-05-30T21:59:59+0000'),
                $this->catalog('permanent-prices', 'Nu endnu lavere priser', '2026-05-12T22:00:00+0000', '2026-12-31T22:59:59+0000'),
                $this->catalog('expired-paper', 'Uge 21', '2026-05-18T22:00:00+0000', '2026-05-25T21:59:59+0000'),
            ]),
            'flwdn2189e-dsn.algolia.net/*' => Http::response($this->algoliaResponse([
                $this->algoliaProduct(60055, 'LIMPAN', '900 GR. / PÅGEN'),
                $this->algoliaProduct(61251, 'STORE HOTDOGBRØD', '360 GR. / REMA 1000'),
                $this->algoliaProduct(404995, 'KYLLINGEBRYSTFILET', '450 GR. / DANSK'),
                $this->algoliaProduct(170209, 'TOILETPAPIR', '684 GR. / REMA 1000'),
                ...array_map(fn (int $id): array => $this->algoliaProduct($id, "UGEVARE {$id}", '200 GR. / REMA 1000'), range(70001, 70008)),
                ...array_map(fn (int $id): array => $this->algoliaProduct($id, "INDSTIKSVARE {$id}", '200 GR. / REMA 1000'), range(80001, 80009)),
            ])),
            'api.digital.rema1000.dk/api/v3/products/60055?*' => Http::response(['data' => $this->productDetail(60055, '2026-05-26T00:00:00+00:00', '2026-05-30T00:00:00+00:00')]),
            'api.digital.rema1000.dk/api/v3/products/61251?*' => Http::response(['data' => $this->productDetail(61251, '2026-05-26T00:00:00+00:00', '2026-05-30T00:00:00+00:00')]),
            'api.digital.rema1000.dk/api/v3/products/404995?*' => Http::response(['data' => $this->productDetail(404995, '2026-05-26T00:00:00+00:00', '2026-06-13T00:00:00+00:00')]),
            'api.digital.rema1000.dk/api/v3/products/170209?*' => Http::response(['data' => $this->productDetail(170209, '2026-05-27T23:00:00+00:00', '2026-05-30T00:00:00+00:00')]),
            ...$this->detailResponses(range(70001, 70008), '2026-05-26T00:00:00+00:00', '2026-05-30T00:00:00+00:00'),
            ...$this->detailResponses(range(80001, 80009), '2026-05-27T23:00:00+00:00', '2026-05-30T00:00:00+00:00'),
        ]);

        $papers = $this->scraperWithoutDelay()->fetchPapers();

        $this->assertCount(2, $papers);
        $this->assertSame('weekly-paper', $papers[0]->sourceExternalId);
        $this->assertSame('insert-paper', $papers[1]->sourceExternalId);

        $weeklyPayload = json_decode($papers[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);
        $insertPayload = json_decode($papers[1]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('algolia_product_details_grouped_by_tjek_overlap', $weeklyPayload['catalog']['source_strategy']);
        $this->assertSame(11, $weeklyPayload['catalog']['fetched_product_offer_count']);
        $this->assertSame(10, $insertPayload['catalog']['fetched_product_offer_count']);
        $this->assertSame('LIMPAN', $papers[0]->offers[0]->title);
        $this->assertSame('TOILETPAPIR', $papers[1]->offers[0]->title);
        $this->assertFalse(collect($papers)->contains(fn ($paper): bool => $paper->sourceExternalId === 'permanent-prices'));
    }

    public function test_it_fails_when_product_detail_coverage_is_too_low(): void
    {
        CarbonImmutable::setTestNow('2026-05-29 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('weekly-paper', 'Uge 22', '2026-05-25T22:00:00+0000', '2026-05-30T21:59:59+0000'),
            ]),
            'flwdn2189e-dsn.algolia.net/*' => Http::response($this->algoliaResponse([
                $this->algoliaProduct(60055, 'LIMPAN', '900 GR. / PÅGEN'),
            ])),
            'api.digital.rema1000.dk/api/v3/products/60055?*' => Http::response(['message' => 'InternalServerError'], 500),
        ]);

        $this->expectException(ScraperFetchException::class);
        $this->expectExceptionMessage('REMA 1000 product detail request failed for product [60055] with upstream server error.');

        $this->scraperWithoutDelay()->fetchPapers();
    }

    private function scraperWithoutDelay(): Rema1000Scraper
    {
        return new Rema1000Scraper(
            mapper: $this->app->make(Rema1000PaperMapper::class),
            catalogs: new Rema1000CatalogSource,
            avisvarer: new Rema1000AvisvareSource,
            details: new Rema1000ProductDetailSource(delayRequests: false),
            grouper: new Rema1000OfferGrouper,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(string $id, string $label, string $runFrom, string $runTill): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'run_from' => $runFrom,
            'run_till' => $runTill,
            'offer_count' => 20,
            'page_count' => 12,
            'dealer_id' => '11deC',
            'dealer' => ['name' => 'REMA 1000'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @return array<string, mixed>
     */
    private function algoliaResponse(array $hits): array
    {
        return ['results' => [['hits' => $hits]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function algoliaProduct(int $id, string $name, string $underline): array
    {
        return [
            'id' => $id,
            'objectID' => (string) $id,
            'name' => $name,
            'underline' => $underline,
            'hf2' => 'REMA 1000',
            'labels' => ['avisvare', 'discount'],
            'description_short' => "Varenummer: {$id}",
            'pricing' => [
                'price' => 10,
                'price_per_unit' => '11.11 per Kg.',
                'max_quantity' => 6,
            ],
            'images' => [[
                'large' => "https://images.example/{$id}.webp",
            ]],
            'department_id' => 10,
            'department_name' => 'Brød & Bavinchi',
            'category_id' => 655390,
            'category_name' => 'Brød',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productDetail(int $id, string $startsAt, string $endsAt): array
    {
        return [
            'id' => $id,
            'bar_codes' => ["570000{$id}"],
            'prices' => [[
                'price' => 10,
                'is_advertised' => true,
                'is_campaign' => true,
                'starting_at' => $startsAt,
                'ending_at' => $endsAt,
                'compare_unit_price' => 11.11,
                'max_quantity' => 6,
            ]],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<string, Response>
     */
    private function detailResponses(array $ids, string $startsAt, string $endsAt): array
    {
        $responses = [];

        foreach ($ids as $id) {
            $responses["api.digital.rema1000.dk/api/v3/products/{$id}?*"] = Http::response(['data' => $this->productDetail($id, $startsAt, $endsAt)]);
        }

        return $responses;
    }
}
