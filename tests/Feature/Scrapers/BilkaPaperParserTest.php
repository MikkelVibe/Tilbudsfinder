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
use App\Scrapers\Bilka\BilkaPaperParser;
use App\Scrapers\Exceptions\ScraperParseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BilkaPaperParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_bilka_fixture_into_paper_input(): void
    {
        $paper = (new BilkaPaperParser)->parse($this->fixture());

        $this->assertSame('Jrf1G_2x', $paper->sourceExternalId);
        $this->assertSame('Bilka Food Uge 23 2026 - Fødevarer & Personlig Pleje', $paper->title);
        $this->assertSame('2026-05-28 22:00:00', $paper->activeFrom->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-04 21:59:59', $paper->activeUntil->format('Y-m-d H:i:s'));
        $this->assertCount(12, $paper->offers);
        $this->assertSame('Bilka', $paper->metadata['dealer_name']);
        $this->assertSame(224, $paper->metadata['offer_count']);
        $this->assertSame(12, $paper->metadata['fetched_offer_count']);
        $this->assertSame(212, $paper->metadata['offer_count_mismatch']);

        $offer = $paper->offers[1];

        $this->assertSame('Coca-Cola sodavand', $offer->title);
        $this->assertSame(11, $offer->price);
        $this->assertSame('Note: Maks. 12 | 1,5 liter. Flere varianter. Ekskl. embl. Pr. liter 7.33. FRIT VALG. MAX. 12 STK TIL DENNE PRIS. 1.5 l', $offer->packageText);
        $this->assertSame('7.33', $offer->sourceUnitPrice);
        $this->assertSame('Maks. 12', $offer->purchaseLimitText);
        $this->assertSame('offer-2', $offer->sourceOfferId);
        $this->assertSame('https://images.example/bilka/cola.webp', $offer->imageUrl);
        $this->assertSame(17, $offer->metadata['catalog_page']);
    }

    public function test_it_persists_parsed_bilka_fixture_through_import_pipeline(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'bilka']);
        $paper = (new BilkaPaperParser)->parse($this->fixture());

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paper);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(12, $batch->parsed_offer_count);
        $this->assertSame(12, $batch->published_offer_count);
        $this->assertTrue(Storage::disk('local')->exists($batch->raw_payload_path));

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->where('source_external_id', 'Jrf1G_2x')->count());
        $this->assertSame(12, ScrapedOffer::query()->count());
        $this->assertGreaterThanOrEqual(0, NormalizationFailure::query()->count());

        $chicken = ScrapedOffer::query()->where('source_offer_id', 'offer-6')->firstOrFail();

        $this->assertSame('Kyllingebrystfilet', $chicken->title);
        $this->assertSame('30.00', $chicken->price);
        $this->assertSame('450.000', $chicken->package_amount);
        $this->assertSame('kg', $chicken->compare_unit);
        $this->assertSame('66.67', $chicken->unit_price);
        $this->assertSame(NormalizationStatus::Succeeded, $chicken->normalization_status);
    }

    public function test_it_rejects_bilka_fixture_with_too_few_offers(): void
    {
        $payload = json_decode($this->fixture(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = array_slice($payload['offers'], 0, 9);

        $this->expectException(ScraperParseException::class);
        $this->expectExceptionMessage('Bilka paper must contain at least 10 parsed offers.');

        (new BilkaPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/scrapers/bilka/food-uge-23-tjek.json'));
    }
}
