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
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\OfferNormalizer;
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
        $this->assertSame('Note: Maks. 12 | 1,5 liter. Flere varianter. Ekskl. embl. Pr. liter 7.33. FRIT VALG. MAX. 12 STK TIL DENNE PRIS.', $offer->sourceUnitPriceText);
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

    public function test_it_rejects_app_only_bilka_offers(): void
    {
        $payload = json_decode($this->fixture(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = array_fill(0, 10, $this->offer([
            'id' => 'app-only',
            'heading' => 'App only offer',
            'description' => 'Note: App-pris 85,00 kr. | PLUS PRIS. GÆLDER KUN MED BILKA PLUS APPEN. 840-920 g. Pr. kg max. 101.19.',
            'pricing' => ['price' => 85, 'currency' => 'DKK'],
        ]));

        $this->expectException(ScraperParseException::class);
        $this->expectExceptionMessage('Bilka paper produced zero publishable offers.');

        (new BilkaPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_it_keeps_mixed_app_and_general_bilka_offers_when_structured_price_is_general(): void
    {
        $payload = json_decode($this->fixture(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = array_fill(0, 10, $this->offer([
            'id' => 'mixed-general',
            'heading' => 'Bag-in-Box marked',
            'description' => 'Note: App-pris 109,00 kr. | PLUS PRIS FRIT VALG. Pr. liter 36.33. GÆLDER KUN MED BILKA PLUS APPEN. FRIT VALG. 129. Italien. Pr. liter 43.-',
            'pricing' => ['price' => 129, 'currency' => 'DKK'],
            'quantity' => [
                'unit' => ['symbol' => 'l'],
                'size' => ['from' => 3, 'to' => 3],
                'pieces' => ['from' => 1, 'to' => 1],
            ],
        ]));

        $paper = (new BilkaPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
        $offer = $paper->offers[0];
        $normalized = (new OfferNormalizer)->normalize($offer);

        $this->assertFalse($offer->isConditional);
        $this->assertSame(NormalizedOfferStatus::Succeeded, $normalized->status);
        $this->assertSame('43.00', $normalized->unitPrice?->decimal());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function offer(array $overrides): array
    {
        return array_replace_recursive([
            'id' => 'offer',
            'heading' => 'Offer',
            'description' => '500 g. Pr. kg 20.',
            'catalog_page' => 1,
            'pricing' => ['price' => 10, 'currency' => 'DKK'],
            'quantity' => [
                'unit' => ['symbol' => 'g'],
                'size' => ['from' => 500, 'to' => 500],
                'pieces' => ['from' => 1, 'to' => 1],
            ],
            'images' => ['zoom' => 'https://images.example/bilka.webp'],
        ], $overrides);
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/scrapers/bilka/food-uge-23-tjek.json'));
    }
}
