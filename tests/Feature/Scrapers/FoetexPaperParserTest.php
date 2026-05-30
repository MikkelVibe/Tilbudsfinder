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
use App\Scrapers\Foetex\FoetexPaperParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FoetexPaperParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_foetex_fixture_into_paper_input(): void
    {
        $paper = (new FoetexPaperParser)->parse($this->fixture());

        $this->assertSame('j-n7jfCA', $paper->sourceExternalId);
        $this->assertSame('Uge 23/24', $paper->title);
        $this->assertSame('2026-05-28 22:00:00', $paper->activeFrom->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-11 21:59:59', $paper->activeUntil->format('Y-m-d H:i:s'));
        $this->assertCount(12, $paper->offers);
        $this->assertSame('føtex', $paper->metadata['dealer_name']);
        $this->assertSame(476, $paper->metadata['offer_count']);
        $this->assertSame(12, $paper->metadata['fetched_offer_count']);
        $this->assertSame(464, $paper->metadata['offer_count_mismatch']);

        $offer = $paper->offers[2];

        $this->assertSame('Ølmarked', $offer->title);
        $this->assertSame(75, $offer->price);
        $this->assertSame('18x33 cl. ds. Grøn Tuborg, Carlsberg Pilsner eller Nordlyst. Gælder kun lukkede forpakninger. Ekskl. embl. Pr. liter max. 12,63 18 x 33 cl', $offer->packageText);
        $this->assertSame('12,63', $offer->sourceUnitPrice);
        $this->assertSame('offer-3', $offer->sourceOfferId);
        $this->assertSame('https://images.example/foetex/oel.webp', $offer->imageUrl);
        $this->assertSame(5, $offer->metadata['catalog_page']);
    }

    public function test_it_persists_parsed_foetex_fixture_through_import_pipeline(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'foetex']);
        $paper = (new FoetexPaperParser)->parse($this->fixture());

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paper);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(12, $batch->parsed_offer_count);
        $this->assertSame(12, $batch->published_offer_count);
        $this->assertTrue(Storage::disk('local')->exists($batch->raw_payload_path));

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->where('source_external_id', 'j-n7jfCA')->count());
        $this->assertSame(12, ScrapedOffer::query()->count());
        $this->assertGreaterThanOrEqual(0, NormalizationFailure::query()->count());

        $eggs = ScrapedOffer::query()->where('source_offer_id', 'offer-1')->firstOrFail();

        $this->assertSame('salling Danske skrabeæg', $eggs->title);
        $this->assertSame('25.00', $eggs->price);
        $this->assertSame('10.000', $eggs->package_amount);
        $this->assertSame('stk', $eggs->compare_unit);
        $this->assertSame('2.50', $eggs->unit_price);
        $this->assertSame(NormalizationStatus::Succeeded, $eggs->normalization_status);
    }

    public function test_it_rejects_foetex_fixture_with_too_few_offers(): void
    {
        $payload = json_decode($this->fixture(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = array_slice($payload['offers'], 0, 9);

        $this->expectException(ScraperParseException::class);
        $this->expectExceptionMessage('føtex paper must contain at least 10 parsed offers.');

        (new FoetexPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/scrapers/foetex/uge-23-24-tjek.json'));
    }
}
