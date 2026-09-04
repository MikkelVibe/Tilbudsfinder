<?php

namespace Tests\Feature\Scrapers;

use App\Enums\ImportBatchStatus;
use App\Imports\ImportPersistencePipeline;
use App\Models\Grocer;
use App\Models\GrocerProduct;
use App\Models\ImportBatch;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Scrapers\Exceptions\ScraperParseException;
use App\Scrapers\Rema1000\Rema1000PaperParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Rema1000PaperParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_matched_rema_products_and_import_issues(): void
    {
        $paper = (new Rema1000PaperParser)->parse($this->fixture());

        $this->assertSame('week-36', $paper->sourceExternalId);
        $this->assertSame('Uge 36', $paper->title);
        $this->assertSame('2026-08-29 22:00:00', $paper->activeFrom->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05 21:59:59', $paper->activeUntil->format('Y-m-d H:i:s'));
        $this->assertTrue($paper->reconcileExistingPaper);
        $this->assertCount(1, $paper->offers);
        $this->assertCount(1, $paper->issues);
        $this->assertSame(2, $paper->metadata['fetched_offer_count']);
        $this->assertSame(1, $paper->metadata['matched_tjek_offer_count']);
        $this->assertSame(1, $paper->metadata['matched_product_count']);

        $offer = $paper->offers[0];

        $this->assertSame('FAXE KONDI', $offer->title);
        $this->assertSame('210617', $offer->sourceProductId);
        $this->assertSame('tjek-faxe', $offer->sourceOfferId);
        $this->assertSame('2026-09-03T00:00:00+00:00', $offer->metadata['price_starts_at']);
        $this->assertSame('Drikkevarer', $offer->metadata['category']);
        $this->assertSame('Sodavand', $offer->metadata['subcategory']);
        $this->assertSame('Vand, kulsyre og naturlig aroma.', $offer->metadata['declaration']);
        $this->assertSame('missing_rema_product', $paper->issues[0]->code);
    }

    public function test_it_persists_matched_rema_fixture_through_import_pipeline(): void
    {
        Storage::fake('local');
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $paper = (new Rema1000PaperParser)->parse($this->fixture());

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paper);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(1, $batch->published_offer_count);
        $this->assertSame(1, $batch->metadata['import_issue_count']);
        $this->assertSame('missing_rema_product', $batch->metadata['import_issues'][0]['code']);
        $this->assertTrue(Storage::disk('local')->exists($batch->raw_payload_path));
        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->where('source_external_id', 'week-36')->count());
        $this->assertSame(1, ScrapedOffer::query()->where('source_product_id', '210617')->count());

        $product = GrocerProduct::query()->where('source_product_id', '210617')->firstOrFail();

        $this->assertSame('Drikkevarer', $product->category);
        $this->assertSame('Sodavand', $product->subcategory);
        $this->assertSame('Vand, kulsyre og naturlig aroma.', $product->declaration);
    }

    public function test_it_allows_a_fully_accounted_zero_match_payload_for_failed_batch_logging(): void
    {
        $payload = json_decode($this->fixture(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = [];
        $payload['catalog']['matched_tjek_offer_count'] = 0;
        $payload['catalog']['matched_product_count'] = 0;
        $payload['catalog']['missing_tjek_offer_count'] = 2;

        $paper = (new Rema1000PaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->assertSame([], $paper->offers);
        $this->assertTrue($paper->reconcileExistingPaper);
    }

    public function test_it_rejects_unreconciled_match_accounting(): void
    {
        $payload = json_decode($this->fixture(), true, flags: JSON_THROW_ON_ERROR);
        $payload['catalog']['missing_tjek_offer_count'] = 0;

        $this->expectException(ScraperParseException::class);
        $this->expectExceptionMessage('Tjek accounting does not reconcile');

        (new Rema1000PaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_it_rejects_obsolete_algolia_payloads(): void
    {
        $payload = json_decode($this->fixture(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = [[
            'algolia' => ['id' => 1, 'name' => 'Old payload'],
            'product_detail' => ['id' => 1],
            'advertised_price' => ['price' => 10],
        ]];

        $this->expectException(ScraperParseException::class);
        $this->expectExceptionMessage('must contain rema_product, advertised_price, and tjek_offer');

        (new Rema1000PaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/scrapers/rema1000/uge-36-matched-flow.json'));
    }
}
