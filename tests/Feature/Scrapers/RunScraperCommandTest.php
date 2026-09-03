<?php

namespace Tests\Feature\Scrapers;

use App\Enums\GrocerHealthStatus;
use App\Enums\ImportBatchStatus;
use App\Imports\Exceptions\ImportPipelineException;
use App\Models\Grocer;
use App\Models\GrocerProduct;
use App\Models\ImportBatch;
use App\Models\OfferSearchDocument;
use App\Models\Paper;
use App\Models\PriceObservation;
use App\Models\ScrapedOffer;
use App\Search\DatabaseOfferSearchEngine;
use App\Search\OfferSearchQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RunScraperCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_persistence_commands_do_not_expose_a_limit_option(): void
    {
        $this->assertFalse(Artisan::all()['scraper:run']->getDefinition()->hasOption('limit'));
        $this->assertFalse(Artisan::all()['scraper-agent:work']->getDefinition()->hasOption('limit'));
    }

    public function test_it_runs_rema_scraper_and_persists_active_papers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeRemaResponses(['weekly-paper' => range(1, 10), 'active-insert' => range(11, 20)]);
        $grocer = Grocer::factory()->create([
            'slug' => 'rema1000',
            'name' => 'REMA 1000',
            'health_status' => GrocerHealthStatus::Failing,
        ]);

        $this->artisan('scraper:run rema1000')
            ->expectsOutput('Scraper [rema1000] completed.')
            ->expectsOutput('Fetched papers: 2')
            ->expectsOutput('Imported papers: 2')
            ->expectsOutput('Skipped duplicates: 0')
            ->assertSuccessful();

        $this->assertSame(2, ImportBatch::query()->count());
        $this->assertSame(2, Paper::query()->count());
        $this->assertSame(20, Paper::query()->withCount('scrapedOffers')->get()->sum('scraped_offers_count'));
        $this->assertSame(GrocerHealthStatus::Healthy, $grocer->refresh()->health_status);
        $this->assertNotNull($grocer->last_success_at);
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

    public function test_it_runs_bilka_scraper_and_persists_active_papers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeBilkaResponses();
        Grocer::factory()->create(['slug' => 'bilka', 'name' => 'Bilka']);

        $this->artisan('scraper:run bilka')
            ->expectsOutput('Scraper [bilka] completed.')
            ->expectsOutput('Fetched papers: 1')
            ->expectsOutput('Imported papers: 1')
            ->expectsOutput('Skipped duplicates: 0')
            ->assertSuccessful();

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->count());
        $this->assertSame(12, Paper::query()->withCount('scrapedOffers')->firstOrFail()->scraped_offers_count);
    }

    public function test_it_runs_nemlig_scraper_and_persists_active_papers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeNemligResponses();
        Grocer::factory()->create(['slug' => 'nemlig', 'name' => 'Nemlig']);

        $this->artisan('scraper:run nemlig')
            ->expectsOutput('Scraper [nemlig] completed.')
            ->expectsOutput('Fetched papers: 1')
            ->expectsOutput('Imported papers: 1')
            ->expectsOutput('Skipped duplicates: 0')
            ->assertSuccessful();

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->count());
        $this->assertSame(12, Paper::query()->withCount('scrapedOffers')->firstOrFail()->scraped_offers_count);
    }

    public function test_it_imports_small_nemlig_interval_papers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeNemligResponses(5);
        Grocer::factory()->create(['slug' => 'nemlig', 'name' => 'Nemlig']);

        $this->artisan('scraper:run nemlig')
            ->expectsOutput('Scraper [nemlig] completed.')
            ->expectsOutput('Fetched papers: 1')
            ->expectsOutput('Imported papers: 1')
            ->expectsOutput('Skipped duplicates: 0')
            ->assertSuccessful();

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->count());
        $this->assertSame(5, ImportBatch::query()->firstOrFail()->parsed_offer_count);
        $this->assertSame(5, Paper::query()->withCount('scrapedOffers')->firstOrFail()->scraped_offers_count);
    }

    public function test_it_reconciles_existing_rema_papers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeRemaResponses(['weekly-paper' => range(1, 10)]);
        Grocer::factory()->create(['slug' => 'rema1000', 'name' => 'REMA 1000']);

        $this->artisan('scraper:run rema1000')->assertSuccessful();

        $this->fakeRemaResponses(['weekly-paper' => range(1, 10)]);

        $this->artisan('scraper:run rema1000')
            ->expectsOutput('Fetched papers: 1')
            ->expectsOutput('Imported papers: 1')
            ->expectsOutput('Skipped duplicates: 0')
            ->assertSuccessful();

        $this->assertSame(2, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->count());
    }

    public function test_it_handles_a_new_weekly_rema_paper_arriving_while_the_current_paper_is_still_active(): void
    {
        config()->set('queue.default', 'sync');
        CarbonImmutable::setTestNow('2026-06-03 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeThursdayOverlapRemaResponses(includeNextPaper: false);
        $grocer = Grocer::factory()->create(['slug' => 'rema1000', 'name' => 'REMA 1000']);

        $this->artisan('scraper:run rema1000')->assertSuccessful();

        $firstCurrentPaperBatch = Paper::query()
            ->where('source_external_id', 'week-current')
            ->valueOrFail('import_batch_id');

        CarbonImmutable::setTestNow('2026-06-04 12:00:00');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeThursdayOverlapRemaResponses(includeNextPaper: true);

        $this->assertSame(0, Artisan::call('scraper:run', ['grocer' => 'rema1000']));
        $output = Artisan::output();

        $this->assertStringContainsString('Fetched papers: 2', $output);
        $this->assertStringContainsString('Imported papers: 2', $output);
        $this->assertStringContainsString('Skipped duplicates: 0', $output);

        $this->assertSame(2, Paper::query()->where('grocer_id', $grocer->id)->count());
        $this->assertSame(3, ImportBatch::query()->where('grocer_id', $grocer->id)->count());
        $this->assertSame(0, ScrapedOffer::query()->where('import_batch_id', $firstCurrentPaperBatch)->publiclyActive()->count());
        $this->assertSame(20, ScrapedOffer::query()->where('grocer_id', $grocer->id)->publiclyActive()->count());
        $this->assertSame(0, ScrapedOffer::query()->where('grocer_id', $grocer->id)->publiclyActive()->whereNull('source_product_id')->count());
        $this->assertSame(15, GrocerProduct::query()->where('grocer_id', $grocer->id)->count());
        $this->assertSame(20, OfferSearchDocument::query()->where('grocer_id', $grocer->id)->count());
        $this->assertSame(15, PriceObservation::query()->where('grocer_id', $grocer->id)->count());

        $sharedProduct = GrocerProduct::query()
            ->where('grocer_id', $grocer->id)
            ->where('source_product_id', '6')
            ->firstOrFail();

        $this->assertSame(2, OfferSearchDocument::query()
            ->whereHas('scrapedOffer', fn ($query) => $query->where('grocer_product_id', $sharedProduct->id))
            ->count());

        $results = app(DatabaseOfferSearchEngine::class)->search(new OfferSearchQuery(
            query: null,
            grocerSlugs: ['rema1000'],
            sort: DatabaseOfferSearchEngine::SORT_RELEVANCE,
            perPage: 50,
        ));

        $this->assertSame(15, $results->total());
        $this->assertSame(15, app(DatabaseOfferSearchEngine::class)->activeProductCountsByGrocer()->get('rema1000'));
        $this->assertSame(5, collect($results->items())->filter(fn (OfferSearchDocument $document): bool => $document->product_offer_count === 2)->count());
    }

    public function test_it_imports_a_later_rema_paper_when_an_earlier_catalog_fetch_fails(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->fakeRemaResponsesWithFailedFirstPaperFetch();
        $grocer = Grocer::factory()->create(['slug' => 'rema1000', 'name' => 'REMA 1000']);
        Exceptions::fake();

        $this->artisan('scraper:run rema1000')
            ->expectsOutputToContain('paper-failed')
            ->assertFailed();

        Exceptions::assertReported(
            fn (ImportPipelineException $exception): bool => $exception->getMessage() === 'Import produced zero publishable offers.',
        );
        Exceptions::assertReportedCount(1);

        $failedBatch = ImportBatch::query()->where('source_external_id', 'paper-failed')->firstOrFail();
        $successfulBatch = ImportBatch::query()->where('source_external_id', 'paper-valid')->firstOrFail();

        $this->assertSame(ImportBatchStatus::Failed, $failedBatch->status);
        $this->assertSame('tjek_catalog_fetch_failed', $failedBatch->metadata['import_issues'][0]['code']);
        $this->assertSame(ImportBatchStatus::Succeeded, $successfulBatch->status);
        $this->assertSame(10, $successfulBatch->published_offer_count);
        $this->assertSame(10, ScrapedOffer::query()->where('import_batch_id', $successfulBatch->id)->count());
        $this->assertSame(GrocerHealthStatus::Failing, $grocer->refresh()->health_status);
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
        $products = [];
        $offerResponses = [];

        foreach ($paperProducts as $catalogId => $productIds) {
            $insert = $catalogId === 'active-insert';
            $catalogs[] = $this->catalog($catalogId, $insert ? 'Uge 23 Indstik' : 'Uge 23', count($productIds), $insert);
            $offerResponses["squid-api.tjek.com/v2/offers?catalog_id={$catalogId}&offset=0&limit=100"] = Http::response(array_map(
                fn (int $productId): array => $this->remaTjekOffer($productId, $catalogId, $insert),
                $productIds,
            ));

            foreach ($productIds as $productId) {
                $products[] = $this->remaProduct($productId, $insert);
            }
        }

        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response($catalogs),
            'api.digital.rema1000.dk/api/search/products*' => Http::response([
                'data' => $products,
                'meta' => ['pagination' => ['last_page' => 1, 'total' => count($products)]],
            ]),
            ...$offerResponses,
        ]);
    }

    private function fakeRemaResponsesWithFailedFirstPaperFetch(): void
    {
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('paper-failed', 'Uge 23', 11),
                $this->catalog('paper-valid', 'Uge 23 Indstik', 10, true),
            ]),
            'api.digital.rema1000.dk/api/search/products*' => Http::response([
                'data' => array_map(fn (int $productId): array => $this->remaProduct($productId, true), range(11, 20)),
                'meta' => ['pagination' => ['last_page' => 1, 'total' => 10]],
            ]),
            'squid-api.tjek.com/v2/offers?catalog_id=paper-failed&offset=0&limit=100' => Http::response(array_map(
                fn (int $productId): array => $this->remaTjekOffer($productId, 'paper-failed'),
                range(1, 10),
            )),
            'squid-api.tjek.com/v2/offers?catalog_id=paper-valid&offset=0&limit=100' => Http::response(array_map(
                fn (int $productId): array => $this->remaTjekOffer($productId, 'paper-valid', true),
                range(11, 20),
            )),
        ]);
    }

    private function fakeThursdayOverlapRemaResponses(bool $includeNextPaper): void
    {
        $currentProductIds = range(1, 10);
        $nextProductIds = range(6, 15);
        $allProductIds = $includeNextPaper ? range(1, 15) : $currentProductIds;
        $catalogs = [
            $this->catalog('week-current', 'Uge 23', count($currentProductIds)),
        ];
        $offerResponses = [
            'squid-api.tjek.com/v2/offers?catalog_id=week-current&offset=0&limit=100' => Http::response(array_map(
                fn (int $productId): array => $this->remaTjekOffer($productId, 'week-current'),
                $currentProductIds,
            )),
        ];

        if ($includeNextPaper) {
            $catalogs[] = [
                ...$this->catalog('week-next', 'Uge 24', count($nextProductIds)),
                'run_from' => '2026-06-03T22:00:00+0000',
                'run_till' => '2026-06-13T21:59:59+0000',
            ];
            $offerResponses['squid-api.tjek.com/v2/offers?catalog_id=week-next&offset=0&limit=100'] = Http::response(array_map(function (int $productId): array {
                return [
                    ...$this->remaTjekOffer($productId, 'week-next'),
                    'run_from' => '2026-06-03T22:00:00+00:00',
                    'run_till' => '2026-06-13T21:59:59+00:00',
                ];
            }, $nextProductIds));
        }

        $products = array_map(function (int $productId): array {
            $product = $this->remaProduct($productId);

            if ($productId >= 6 && $productId <= 10) {
                $product['prices'][0]['ending_at'] = '2026-06-13T21:00:00+00:00';
            } elseif ($productId >= 11) {
                $product['prices'][0]['starting_at'] = '2026-06-03T22:30:00+00:00';
                $product['prices'][0]['ending_at'] = '2026-06-13T21:00:00+00:00';
            }

            return $product;
        }, $allProductIds);

        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response($catalogs),
            'api.digital.rema1000.dk/api/search/products*' => Http::response([
                'data' => $products,
                'meta' => ['pagination' => ['last_page' => 1, 'total' => count($products)]],
            ]),
            ...$offerResponses,
        ]);
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
            'drp4o45g5t-dsn.algolia.net/1/indexes/prod_FOETEX_PRODUCTS/query' => Http::response([
                'nbHits' => 0,
                'nbPages' => 0,
                'hits' => [],
            ]),
        ]);
    }

    private function fakeBilkaResponses(): void
    {
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->bilkaCatalog('nonfood-paper', 'Bilka Nonfood Uge 23 2026 - Elektronik, Bolig, Have & Tekstil', 12),
                $this->bilkaCatalog('food-paper', 'Bilka Food Uge 23 2026 - Fødevarer & Personlig Pleje', 12),
            ]),
            'f9vbjlr1bk-dsn.algolia.net/1/indexes/prod_BILKATOGO_PRODUCTS/query' => Http::response([
                'nbHits' => 12,
                'nbPages' => 1,
                'hits' => array_map(fn (int $number): array => $this->bilkaOffer($number), range(1, 12)),
            ]),
        ]);
    }

    private function fakeNemligResponses(int $offerCount = 12): void
    {
        Http::fake([
            'www.nemlig.com/tilbud*' => Http::response([
                'Settings' => [
                    'TimeslotUtc' => '2026060208-60-600',
                    'DeliveryZoneId' => 1,
                    'ProductsImportedTimestamp' => 'AAAAAAAA',
                    'CombinedProductsAndSitecoreTimestamp' => 'AAAAAAAA-oLJ90N-_',
                    'BuildVersion' => 'b1.0.9606.11183',
                ],
                'content' => [
                    ['Heading' => 'Sponsoreret', 'ProductGroupId' => 'sponsored-group', 'TotalProducts' => 7],
                    ['Heading' => 'Skarp pris', 'ProductGroupId' => 'group-1', 'TotalProducts' => $offerCount],
                ],
            ]),
            'www.nemlig.com/webapi/Token' => Http::response(['access_token' => 'test-token']),
            'www.nemlig.com/webapi/AAAAAAAA-oLJ90N-_/2026060208-60-600/1/0/Products/GetByProductGroupId?productGroupId=group-1&pageIndex=0&pagesize=200' => Http::response([
                'Products' => array_map(fn (int $number): array => $this->nemligOffer($number), range(1, $offerCount)),
                'ProductGroupId' => 'group-1',
                'Start' => 0,
                'NumFound' => $offerCount,
            ]),
            'www.nemlig.com/webapi/AAAAAAAA/2026060208-60-600/1/0/Products/Get?id=*' => Http::response([
                'Declarations' => ['ShowDeclarations' => false],
                'Attributes' => [],
            ]),
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
    private function remaProduct(int $id, bool $insert = false): array
    {
        return [
            'id' => $id,
            'name' => "ITEM{$id}",
            'underline' => '200 GR. / REMA 1000',
            'barcodes' => ['570000'.str_pad((string) $id, 7, '0', STR_PAD_LEFT)],
            'prices' => [[
                'price' => 10,
                'is_advertised' => true,
                'is_campaign' => true,
                'starting_at' => $insert ? '2026-05-31T22:30:00+00:00' : '2026-05-30T22:30:00+00:00',
                'ending_at' => $insert ? '2026-06-05T21:00:00+00:00' : '2026-06-06T21:00:00+00:00',
                'compare_unit_price' => 50,
                'compare_unit' => 'kg',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function remaTjekOffer(int $id, string $catalogId, bool $insert = false): array
    {
        return [
            'id' => "offer-{$catalogId}-{$id}",
            'catalog_id' => $catalogId,
            'heading' => "ITEM{$id}",
            'description' => '200 g',
            'pricing' => ['price' => 10],
            'quantity' => ['size' => ['from' => 200, 'to' => 200], 'unit' => ['symbol' => 'g']],
            'run_from' => $insert ? '2026-05-31T22:00:00+00:00' : '2026-05-30T22:00:00+00:00',
            'run_till' => $insert ? '2026-06-05T21:59:59+00:00' : '2026-06-06T21:59:59+00:00',
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

    /**
     * @return array<string, mixed>
     */
    private function bilkaCatalog(string $id, string $label, int $offerCount): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'run_from' => '2026-05-28T22:00:00+0000',
            'run_till' => '2026-06-04T21:59:59+0000',
            'offer_count' => $offerCount,
            'page_count' => 50,
            'dealer_id' => '93f13',
            'dealer' => ['name' => 'Bilka'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bilkaOffer(int $number): array
    {
        return [
            'objectID' => "bilka-product-{$number}",
            'name' => "Bilka Product {$number}",
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

    /**
     * @return array<string, mixed>
     */
    private function nemligOffer(int $number): array
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
                'ShowCampaignInterval' => true,
            ],
        ];
    }
}
