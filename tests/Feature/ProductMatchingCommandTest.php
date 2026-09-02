<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationStatus;
use App\Imports\DTO\ParsedPaperInput;
use App\Imports\ImportPersistencePipeline;
use App\Jobs\MatchImportBatchProducts;
use App\Models\CanonicalProduct;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\OfferSearchDocument;
use App\Models\Paper;
use App\Models\PriceObservation;
use App\Models\ProductIdentifier;
use App\Models\ScrapedOffer;
use App\Normalization\DTO\ParsedOfferInput;
use App\ProductMatching\ProductMatcher;
use App\Search\OfferSearchDocumentBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductMatchingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_pipeline_dispatches_matching_job_after_successful_import(): void
    {
        Queue::fake();
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $paper = new ParsedPaperInput(
            sourceExternalId: 'paper-1',
            activeFrom: CarbonImmutable::parse('2026-06-01T00:00:00+00:00'),
            activeUntil: CarbonImmutable::parse('2026-06-08T00:00:00+00:00'),
            offers: array_map(fn (int $index): ParsedOfferInput => new ParsedOfferInput(
                title: "Offer {$index}",
                price: 10,
                packageText: '500 g',
                sourceProductId: (string) $index,
                sourcePayload: ['catalog_product' => ['bar_codes' => ['7311070338188']]],
            ), range(1, 10)),
            title: 'Paper 1',
            rawPayload: '{}',
        );

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paper);

        Queue::assertPushed(MatchImportBatchProducts::class, fn (MatchImportBatchProducts $job): bool => $job->importBatch->is($batch));
    }

    public function test_it_creates_canonical_product_from_single_ean_offer(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $batch = ImportBatch::factory()->for($grocer)->create(['status' => ImportBatchStatus::Succeeded]);
        $paper = Paper::factory()->for($grocer)->for($batch)->create([
            'active_from' => '2026-05-26T00:00:00+00:00',
            'active_until' => '2026-05-30T00:00:00+00:00',
        ]);

        ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->create([
            'source_product_id' => '60055',
            'title' => 'LIMPAN',
            'price' => '10.00',
            'package_amount' => '900.000',
            'package_unit' => 'g',
            'compare_unit' => 'kg',
            'unit_price' => '11.11',
            'normalization_status' => NormalizationStatus::Succeeded,
            'source_payload' => $this->sourcePayload(['7311070338188']),
        ]);

        $this->artisan("products:match {$batch->id}")
            ->expectsOutput('Product matching completed.')
            ->expectsOutput('Matched: 1')
            ->expectsOutput('Ambiguous: 0')
            ->expectsOutput('Skipped: 0')
            ->expectsOutput('Conflicts: 0')
            ->assertSuccessful();

        $product = CanonicalProduct::query()->firstOrFail();

        $this->assertSame('LIMPAN', $product->name);
        $this->assertSame('PÅGEN', $product->brand);
        $this->assertSame('900.000', $product->package_amount);

        $this->assertSame(1, ProductIdentifier::query()->count());
        $this->assertDatabaseHas('product_identifiers', ['type' => 'ean', 'value' => '7311070338188', 'grocer_id' => null]);
        $this->assertDatabaseHas('product_matches', ['canonical_product_id' => $product->id, 'status' => 'matched', 'confidence' => 100]);
        $this->assertSame(1, PriceObservation::query()->count());
    }

    public function test_it_attaches_multiple_barcodes_to_one_canonical_product(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $batch = ImportBatch::factory()->for($grocer)->create(['status' => ImportBatchStatus::Succeeded]);
        $paper = Paper::factory()->for($grocer)->for($batch)->create();

        ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->create([
            'source_payload' => $this->sourcePayload(['7311070338188', '7311070338195']),
        ]);

        $this->artisan("products:match {$batch->id}")
            ->expectsOutput('Matched: 1')
            ->expectsOutput('Ambiguous: 0')
            ->assertSuccessful();

        $product = CanonicalProduct::query()->firstOrFail();

        $this->assertSame(1, CanonicalProduct::query()->count());
        $this->assertSame(1, PriceObservation::query()->count());
        $this->assertSame(2, ProductIdentifier::query()->count());
        $this->assertDatabaseHas('product_identifiers', ['canonical_product_id' => $product->id, 'type' => 'ean', 'value' => '7311070338188']);
        $this->assertDatabaseHas('product_identifiers', ['canonical_product_id' => $product->id, 'type' => 'ean', 'value' => '7311070338195']);
        $this->assertDatabaseHas('product_matches', ['status' => 'matched', 'canonical_product_id' => $product->id]);
    }

    public function test_it_matches_dagrofa_numeric_sku_to_existing_rema_ean_product(): void
    {
        $rema = Grocer::factory()->create(['slug' => 'rema1000']);
        $dagrofa = Grocer::factory()->create(['slug' => 'spar']);
        $remaBatch = ImportBatch::factory()->for($rema)->create(['status' => ImportBatchStatus::Succeeded]);
        $dagrofaBatch = ImportBatch::factory()->for($dagrofa)->create(['status' => ImportBatchStatus::Succeeded]);
        $remaPaper = Paper::factory()->for($rema)->for($remaBatch)->create();
        $dagrofaPaper = Paper::factory()->for($dagrofa)->for($dagrofaBatch)->create();

        ScrapedOffer::factory()->for($rema)->for($remaBatch)->for($remaPaper)->create([
            'title' => 'HOTDOG PØLSER',
            'source_product_id' => '60055',
            'source_payload' => $this->sourcePayload(['5707196133561']),
        ]);

        ScrapedOffer::factory()->for($dagrofa)->for($dagrofaBatch)->for($dagrofaPaper)->create([
            'title' => 'Steff-H Hotdog Pølser',
            'source_product_id' => '5707196133561',
            'source_payload' => ['sku' => '5707196133561', 'productDisplayName' => 'Steff-H Hotdog Pølser'],
        ]);

        $this->artisan("products:match {$remaBatch->id}")->assertSuccessful();
        $this->artisan("products:match {$dagrofaBatch->id}")->assertSuccessful();

        $product = CanonicalProduct::query()->firstOrFail();

        $this->assertSame(1, CanonicalProduct::query()->count());
        $this->assertSame(2, PriceObservation::query()->count());
        $this->assertDatabaseHas('product_identifiers', ['canonical_product_id' => $product->id, 'type' => 'ean', 'value' => '5707196133561', 'grocer_id' => null]);
    }

    public function test_it_matches_salling_enriched_offer_to_existing_ean_product(): void
    {
        $rema = Grocer::factory()->create(['slug' => 'rema1000']);
        $bilka = Grocer::factory()->create(['slug' => 'bilka']);
        $remaBatch = ImportBatch::factory()->for($rema)->create(['status' => ImportBatchStatus::Succeeded]);
        $bilkaBatch = ImportBatch::factory()->for($bilka)->create(['status' => ImportBatchStatus::Succeeded]);
        $remaPaper = Paper::factory()->for($rema)->for($remaBatch)->create();
        $bilkaPaper = Paper::factory()->for($bilka)->for($bilkaBatch)->create();

        ScrapedOffer::factory()->for($rema)->for($remaBatch)->for($remaPaper)->create([
            'title' => 'Agurk',
            'source_product_id' => '41286',
            'source_payload' => $this->sourcePayload(['5711044475956']),
        ]);

        ScrapedOffer::factory()->for($bilka)->for($bilkaBatch)->for($bilkaPaper)->create([
            'title' => 'Agurk',
            'source_product_id' => 'salling-product-1',
            'source_payload' => [
                'heading' => 'Agurk',
                '_salling_enrichment' => [
                    'source_product_id' => 'salling-product-1',
                    'eans' => ['5711044475956'],
                ],
            ],
        ]);

        $this->artisan("products:match {$remaBatch->id}")->assertSuccessful();
        $this->artisan("products:match {$bilkaBatch->id}")->assertSuccessful();

        $product = CanonicalProduct::query()->firstOrFail();

        $this->assertSame(1, CanonicalProduct::query()->count());
        $this->assertSame(2, PriceObservation::query()->count());
        $this->assertDatabaseHas('product_identifiers', ['canonical_product_id' => $product->id, 'type' => 'ean', 'value' => '5711044475956', 'grocer_id' => null]);
    }

    public function test_it_matches_pending_batches_synchronously(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $batch = ImportBatch::factory()->for($grocer)->create(['status' => ImportBatchStatus::Succeeded]);
        $paper = Paper::factory()->for($grocer)->for($batch)->create();

        ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->create([
            'source_payload' => $this->sourcePayload(['7311070338188']),
        ]);

        $this->artisan('products:match-pending --sync')
            ->expectsOutputToContain("Matched batch {$batch->id}")
            ->expectsOutput('Processed import batches: 1')
            ->assertSuccessful();

        $this->assertSame(1, CanonicalProduct::query()->count());
        $this->assertSame(1, ProductIdentifier::query()->count());
        $this->assertSame(1, PriceObservation::query()->count());
    }

    public function test_matching_job_refreshes_canonical_search_fields_for_the_current_batch(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $batch = ImportBatch::factory()->for($grocer)->create(['status' => ImportBatchStatus::Succeeded]);
        $paper = Paper::factory()->for($grocer)->for($batch)->create();
        $offer = ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->create([
            'title' => 'LIMPAN',
            'source_payload' => $this->sourcePayload(['7311070338188']),
        ]);
        $builder = new OfferSearchDocumentBuilder;
        $builder->rebuildForImportBatch($batch);

        $this->assertNull(OfferSearchDocument::query()->where('scraped_offer_id', $offer->id)->value('canonical_product_id'));

        (new MatchImportBatchProducts($batch))->handle(new ProductMatcher, $builder);

        $document = OfferSearchDocument::query()->where('scraped_offer_id', $offer->id)->firstOrFail();
        $this->assertNotNull($document->canonical_product_id);
        $this->assertSame('LIMPAN', $document->canonical_product_name);
    }

    public function test_matching_job_skips_a_superseded_reconciliation_batch(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $oldBatch = ImportBatch::factory()->for($grocer)->create(['status' => ImportBatchStatus::Succeeded]);
        $currentBatch = ImportBatch::factory()->for($grocer)->create(['status' => ImportBatchStatus::Succeeded]);
        $paper = Paper::factory()->for($grocer)->for($currentBatch)->create();
        $oldOffer = ScrapedOffer::factory()->for($grocer)->for($oldBatch)->for($paper)->create([
            'source_payload' => $this->sourcePayload(['7311070338188']),
        ]);

        (new MatchImportBatchProducts($oldBatch))->handle(new ProductMatcher, new OfferSearchDocumentBuilder);

        $this->assertNull($oldOffer->productMatch()->first());
        $this->assertSame(0, CanonicalProduct::query()->count());
    }

    public function test_matching_queue_retry_windows_exceed_the_job_timeout(): void
    {
        $job = new MatchImportBatchProducts(new ImportBatch);

        $this->assertGreaterThan($job->timeout, config('queue.connections.database.retry_after'));
        $this->assertGreaterThan($job->timeout, config('queue.connections.redis.retry_after'));
        $this->assertSame([5, 30, 90], $job->backoff);
    }

    /**
     * @param  list<string>  $barcodes
     * @return array<string, mixed>
     */
    private function sourcePayload(array $barcodes): array
    {
        return [
            'catalog_product' => [
                'id' => 60055,
                'name' => 'LIMPAN',
                'hf2' => 'PÅGEN',
                'declaration' => 'Ingredienser',
                'bar_codes' => $barcodes,
                'nutrition_info' => [
                    ['name' => 'Energi', 'value' => '1.061 KJ / 251 kcal', 'sort' => '1'],
                    ['name' => 'Fedt', 'value' => '2,2', 'sort' => '2'],
                    ['name' => 'Heraf mættede fedtsyrer', 'value' => '0,4', 'sort' => '3'],
                    ['name' => 'Kulhydrat', 'value' => '49', 'sort' => '4'],
                    ['name' => 'Heraf sukkerarter', 'value' => '3,2', 'sort' => '5'],
                    ['name' => 'Kostfibre', 'value' => '2,6', 'sort' => '6'],
                    ['name' => 'Protein', 'value' => '7,4', 'sort' => '7'],
                    ['name' => 'Salt', 'value' => '1,0', 'sort' => '8'],
                ],
            ],
            'product_detail' => [
                'id' => 60055,
                'bar_codes' => $barcodes,
            ],
        ];
    }
}
