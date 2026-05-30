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
use App\Scrapers\Exceptions\ScraperParseException;
use App\Scrapers\Netto\NettoPaperParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NettoPaperParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_netto_fixture_into_paper_input(): void
    {
        $paper = (new NettoPaperParser)->parse($this->fixture());

        $this->assertSame('DX0mvqOS', $paper->sourceExternalId);
        $this->assertSame('Uge 23', $paper->title);
        $this->assertSame('2026-05-29 22:00:00', $paper->activeFrom->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-05 21:59:59', $paper->activeUntil->format('Y-m-d H:i:s'));
        $this->assertCount(12, $paper->offers);
        $this->assertSame('Netto', $paper->metadata['dealer_name']);
        $this->assertSame(211, $paper->metadata['offer_count']);
        $this->assertSame(12, $paper->metadata['fetched_offer_count']);
        $this->assertSame(199, $paper->metadata['offer_count_mismatch']);

        $offer = $paper->offers[2];

        $this->assertSame('Tuborg Grøn eller Carlsberg Pilsner', $offer->title);
        $this->assertSame(69, $offer->price);
        $this->assertSame('Note: Maks. 6 | 18x33 cl. ds. + Pant. Pr. liter 11,62. Max 6 rammer pr. variant pr. kunde pr. dag til denne pris. 18 x 33 cl', $offer->packageText);
        $this->assertSame('11,62', $offer->sourceUnitPrice);
        $this->assertSame('Maks. 6', $offer->purchaseLimitText);
        $this->assertSame('offer-3', $offer->sourceOfferId);
        $this->assertSame('https://images.example/netto/oel.webp', $offer->imageUrl);
        $this->assertSame(6, $offer->metadata['catalog_page']);
    }

    public function test_it_persists_parsed_netto_fixture_through_import_pipeline(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'netto']);
        $paper = (new NettoPaperParser)->parse($this->fixture());

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paper);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(12, $batch->parsed_offer_count);
        $this->assertSame(12, $batch->published_offer_count);
        $this->assertTrue(Storage::disk('local')->exists($batch->raw_payload_path));

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->where('source_external_id', 'DX0mvqOS')->count());
        $this->assertSame(12, ScrapedOffer::query()->count());
        $this->assertGreaterThanOrEqual(0, NormalizationFailure::query()->count());

        $strawberries = ScrapedOffer::query()->where('source_offer_id', 'offer-1')->firstOrFail();

        $this->assertSame('Danske jordbær', $strawberries->title);
        $this->assertSame('25.00', $strawberries->price);
        $this->assertSame('350.000', $strawberries->package_amount);
        $this->assertSame('kg', $strawberries->compare_unit);
        $this->assertSame('71.43', $strawberries->unit_price);
        $this->assertSame(NormalizationStatus::Succeeded, $strawberries->normalization_status);
    }

    public function test_it_rejects_netto_fixture_with_too_few_offers(): void
    {
        $payload = json_decode($this->fixture(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = array_slice($payload['offers'], 0, 9);

        $this->expectException(ScraperParseException::class);
        $this->expectExceptionMessage('Netto paper must contain at least 10 parsed offers.');

        (new NettoPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/scrapers/netto/uge-23-tjek.json'));
    }
}
