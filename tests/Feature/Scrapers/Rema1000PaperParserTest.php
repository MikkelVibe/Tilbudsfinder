<?php

namespace Tests\Feature\Scrapers;

use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationStatus;
use App\Imports\ImportPersistencePipeline;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\NormalizationFailure;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Scrapers\Rema1000\Rema1000PaperMapper;
use App\Scrapers\Rema1000\Rema1000PaperParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Rema1000PaperParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_product_level_rema_payload(): void
    {
        $paper = $this->parser()->parse($this->productPayload());

        $this->assertSame('weekly-paper', $paper->sourceExternalId);
        $this->assertSame('Uge 22', $paper->title);
        $this->assertSame('2026-05-25 22:00:00', $paper->activeFrom->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-30 21:59:59', $paper->activeUntil->format('Y-m-d H:i:s'));
        $this->assertSame('algolia_product_details_grouped_by_tjek_overlap', $paper->metadata['source_strategy']);
        $this->assertSame(10, $paper->metadata['fetched_product_offer_count']);
        $this->assertCount(10, $paper->offers);

        $offer = $paper->offers[0];

        $this->assertSame('PRODUCT 1', $offer->title);
        $this->assertSame(10, $offer->price);
        $this->assertSame('200 GR. / REMA 1000', $offer->packageText);
        $this->assertSame(50, $offer->sourceUnitPrice);
        $this->assertSame('1', $offer->sourceProductId);
        $this->assertSame('Maks. 6', $offer->purchaseLimitText);
        $this->assertSame('Brod', $offer->metadata['category_name']);
        $this->assertSame('2026-05-26T00:00:00+00:00', $offer->metadata['price_starts_at']);
    }

    public function test_it_persists_parsed_rema_payload_through_import_pipeline(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $paper = $this->parser()->parse($this->productPayload());

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paper);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(10, $batch->parsed_offer_count);
        $this->assertSame(10, $batch->published_offer_count);
        $this->assertSame(0, $batch->normalization_failure_count);
        $this->assertTrue(Storage::disk('local')->exists($batch->raw_payload_path));

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->where('source_external_id', 'weekly-paper')->count());
        $this->assertSame(10, ScrapedOffer::query()->count());
        $this->assertSame(0, NormalizationFailure::query()->count());

        $offer = ScrapedOffer::query()->where('source_product_id', '1')->firstOrFail();

        $this->assertSame('PRODUCT 1', $offer->title);
        $this->assertSame('10.00', $offer->price);
        $this->assertSame('200.000', $offer->package_amount);
        $this->assertSame('g', $offer->package_unit);
        $this->assertSame('kg', $offer->compare_unit);
        $this->assertSame('50.00', $offer->unit_price);
        $this->assertSame(NormalizationStatus::Succeeded, $offer->normalization_status);
    }

    public function test_it_rejects_rema_payload_with_too_few_offers(): void
    {
        $payload = json_decode($this->productPayload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = array_slice($payload['offers'], 0, 9);

        $this->expectExceptionMessage('Parsed paper must contain at least 10 offers.');

        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $paper = $this->parser()->parse(json_encode($payload, JSON_THROW_ON_ERROR));

        (new ImportPersistencePipeline)->persist($grocer, $paper);
    }

    private function parser(): Rema1000PaperParser
    {
        return new Rema1000PaperParser(new Rema1000PaperMapper);
    }

    private function productPayload(): string
    {
        return file_get_contents(base_path('tests/Fixtures/Scrapers/Rema1000/product-paper.json')) ?: '';
    }
}
