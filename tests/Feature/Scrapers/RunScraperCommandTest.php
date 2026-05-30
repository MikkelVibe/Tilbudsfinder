<?php

namespace Tests\Feature\Scrapers;

use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\Paper;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RunScraperCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_runs_rema_scraper_and_persists_active_papers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeRemaResponses(['weekly-paper' => range(1, 10), 'active-insert' => range(11, 20)]);
        Grocer::factory()->create(['slug' => 'rema1000', 'name' => 'REMA 1000']);

        $this->artisan('scraper:run rema1000 --no-delay')
            ->expectsOutput('Scraper [rema1000] completed.')
            ->expectsOutput('Fetched papers: 2')
            ->expectsOutput('Imported papers: 2')
            ->expectsOutput('Skipped duplicates: 0')
            ->assertSuccessful();

        $this->assertSame(2, ImportBatch::query()->count());
        $this->assertSame(2, Paper::query()->count());
        $this->assertSame(20, Paper::query()->withCount('scrapedOffers')->get()->sum('scraped_offers_count'));
    }

    public function test_it_runs_netto_scraper_and_persists_active_papers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeNettoResponses();
        Grocer::factory()->create(['slug' => 'netto', 'name' => 'Netto']);

        $this->artisan('scraper:run netto')
            ->expectsOutput('Scraper [netto] completed.')
            ->expectsOutput('Fetched papers: 1')
            ->expectsOutput('Imported papers: 1')
            ->expectsOutput('Skipped duplicates: 0')
            ->assertSuccessful();

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->count());
        $this->assertSame(12, Paper::query()->withCount('scrapedOffers')->firstOrFail()->scraped_offers_count);
    }

    public function test_it_runs_foetex_scraper_and_persists_active_papers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeFoetexResponses();
        Grocer::factory()->create(['slug' => 'foetex', 'name' => 'føtex']);

        $this->artisan('scraper:run foetex')
            ->expectsOutput('Scraper [foetex] completed.')
            ->expectsOutput('Fetched papers: 1')
            ->expectsOutput('Imported papers: 1')
            ->expectsOutput('Skipped duplicates: 0')
            ->assertSuccessful();

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->count());
        $this->assertSame(12, Paper::query()->withCount('scrapedOffers')->firstOrFail()->scraped_offers_count);
    }

    public function test_it_skips_duplicate_rema_papers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeRemaResponses(['weekly-paper' => range(1, 10)]);
        Grocer::factory()->create(['slug' => 'rema1000', 'name' => 'REMA 1000']);

        $this->artisan('scraper:run rema1000 --no-delay')->assertSuccessful();

        $this->fakeRemaResponses(['weekly-paper' => range(1, 10)]);

        $this->artisan('scraper:run rema1000 --no-delay')
            ->expectsOutput('Fetched papers: 1')
            ->expectsOutput('Imported papers: 0')
            ->expectsOutput('Skipped duplicates: 1')
            ->assertSuccessful();

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->count());
    }

    public function test_it_fails_when_rema_grocer_row_is_missing(): void
    {
        Http::preventStrayRequests();

        $this->artisan('scraper:run rema1000')
            ->expectsOutput('Grocer [rema1000] does not exist.')
            ->assertFailed();
    }

    /**
     * @param  array<string, list<int>>  $paperProducts
     */
    private function fakeRemaResponses(array $paperProducts): void
    {
        $catalogs = [];
        $hits = [];
        $detailResponses = [];

        foreach ($paperProducts as $catalogId => $productIds) {
            $catalogs[] = $this->catalog($catalogId, $catalogId === 'active-insert' ? 'Uge 23 Indstik' : 'Uge 23', 20, $catalogId === 'active-insert');

            foreach ($productIds as $productId) {
                $hits[] = $this->algoliaProduct($productId);
                $detailResponses["api.digital.rema1000.dk/api/v3/products/{$productId}?*"] = Http::response(['data' => $this->productDetail($productId, $catalogId === 'active-insert')]);
            }
        }

        $responses = [
            'squid-api.tjek.com/v2/catalogs*' => Http::response($catalogs),
            'flwdn2189e-dsn.algolia.net/*' => Http::response(['results' => [['hits' => $hits]]]),
            ...$detailResponses,
        ];

        Http::fake($responses);
    }

    private function fakeNettoResponses(): void
    {
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->nettoCatalog('weekly-paper', 'Uge 23', 12),
                $this->nettoCatalog('price-shock', 'PRIS CHOK på masser af hverdagsfavoritter', 12),
            ]),
            'squid-api.tjek.com/v2/offers?catalog_id=weekly-paper&offset=0&limit=100' => Http::response(array_map(fn (int $number): array => $this->nettoOffer($number), range(1, 12))),
        ]);
    }

    private function fakeFoetexResponses(): void
    {
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->foetexCatalog('weekly-paper', 'Uge 23/24', 12),
                $this->foetexCatalog('summer-beauty', 'Sommerskøn', 12),
            ]),
            'squid-api.tjek.com/v2/offers?catalog_id=weekly-paper&offset=0&limit=100' => Http::response(array_map(fn (int $number): array => $this->foetexOffer($number), range(1, 12))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(string $id, string $label, int $offerCount, bool $insert = false): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'run_from' => $insert ? '2026-05-31T22:00:00+0000' : '2026-05-30T22:00:00+0000',
            'run_till' => $insert ? '2026-06-05T21:59:59+0000' : '2026-06-06T21:59:59+0000',
            'offer_count' => $offerCount,
            'page_count' => 12,
            'dealer_id' => '11deC',
            'dealer' => ['name' => 'REMA 1000'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function algoliaProduct(int $id): array
    {
        return [
            'id' => $id,
            'objectID' => (string) $id,
            'name' => "Product {$id}",
            'underline' => '200 GR. / REMA 1000',
            'labels' => ['avisvare', 'discount'],
            'pricing' => ['price' => 10, 'price_per_unit' => '50.00 per Kg.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productDetail(int $id, bool $insert = false): array
    {
        return [
            'id' => $id,
            'bar_codes' => ["570000{$id}"],
            'prices' => [[
                'price' => 10,
                'is_advertised' => true,
                'is_campaign' => true,
                'starting_at' => $insert ? '2026-05-31T22:30:00+00:00' : '2026-05-30T22:30:00+00:00',
                'ending_at' => $insert ? '2026-06-05T21:00:00+00:00' : '2026-06-06T21:00:00+00:00',
                'compare_unit_price' => 50,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nettoCatalog(string $id, string $label, int $offerCount): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'run_from' => '2026-05-29T22:00:00+0000',
            'run_till' => '2026-06-05T21:59:59+0000',
            'offer_count' => $offerCount,
            'page_count' => 36,
            'dealer_id' => '9ba51',
            'dealer' => ['name' => 'Netto'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nettoOffer(int $number): array
    {
        return [
            'id' => "netto-offer-{$number}",
            'heading' => "Netto Product {$number}",
            'description' => '200 g. Pr. kg 50,00.',
            'catalog_page' => $number,
            'pricing' => ['price' => 10, 'currency' => 'DKK'],
            'quantity' => [
                'unit' => ['symbol' => 'g'],
                'size' => ['from' => 200, 'to' => 200],
                'pieces' => ['from' => 1, 'to' => 1],
            ],
            'images' => ['zoom' => "https://images.example/netto/{$number}.webp"],
            'catalog_id' => 'weekly-paper',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function foetexCatalog(string $id, string $label, int $offerCount): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'run_from' => '2026-05-28T22:00:00+0000',
            'run_till' => '2026-06-11T21:59:59+0000',
            'offer_count' => $offerCount,
            'page_count' => 93,
            'dealer_id' => 'bdf5A',
            'dealer' => ['name' => 'føtex'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function foetexOffer(int $number): array
    {
        return [
            'id' => "foetex-offer-{$number}",
            'heading' => "føtex Product {$number}",
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
}
