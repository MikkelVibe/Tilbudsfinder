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
use App\Scrapers\Rema1000\Rema1000PaperParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Rema1000PaperParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_rema_fixture_into_paper_input(): void
    {
        $paper = (new Rema1000PaperParser)->parse($this->fixture());

        $this->assertSame('zLEWCiXQ', $paper->sourceExternalId);
        $this->assertSame('Uge 23', $paper->title);
        $this->assertSame('2026-05-30 22:00:00', $paper->activeFrom->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-06 21:59:59', $paper->activeUntil->format('Y-m-d H:i:s'));
        $this->assertCount(12, $paper->offers);
        $this->assertSame('REMA 1000', $paper->metadata['dealer_name']);
        $this->assertSame(147, $paper->metadata['offer_count']);
        $this->assertSame(12, $paper->metadata['fetched_offer_count']);
        $this->assertSame(135, $paper->metadata['offer_count_mismatch']);

        $offer = $paper->offers[3];

        $this->assertSame('Grillpølser', $offer->title);
        $this->assertSame(10, $offer->price);
        $this->assertSame('200 g. 50.00 pr. kg 200 g', $offer->packageText);
        $this->assertSame('IL50sUKQocj-efUeh-jND', $offer->sourceOfferId);
        $this->assertSame('https://images.example/rema/grillpoelser-zoom.webp', $offer->imageUrl);
        $this->assertSame(6, $offer->metadata['catalog_page']);
    }

    public function test_it_persists_parsed_rema_fixture_through_import_pipeline(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $paper = (new Rema1000PaperParser)->parse($this->fixture());

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paper);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(12, $batch->parsed_offer_count);
        $this->assertSame(12, $batch->published_offer_count);
        $this->assertSame(0, $batch->normalization_failure_count);
        $this->assertTrue(Storage::disk('local')->exists($batch->raw_payload_path));

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->where('source_external_id', 'zLEWCiXQ')->count());
        $this->assertSame(12, ScrapedOffer::query()->count());
        $this->assertSame(0, NormalizationFailure::query()->count());

        $grillSausages = ScrapedOffer::query()->where('source_offer_id', 'IL50sUKQocj-efUeh-jND')->firstOrFail();

        $this->assertSame('Grillpølser', $grillSausages->title);
        $this->assertSame('10.00', $grillSausages->price);
        $this->assertSame('200.000', $grillSausages->package_amount);
        $this->assertSame('kg', $grillSausages->compare_unit);
        $this->assertSame('50.00', $grillSausages->unit_price);
        $this->assertSame(NormalizationStatus::Succeeded, $grillSausages->normalization_status);
    }

    public function test_it_parses_product_level_rema_payload(): void
    {
        $payload = [
            'catalog' => [
                'id' => 'weekly-paper',
                'label' => 'Uge 22',
                'run_from' => '2026-05-25T22:00:00+0000',
                'run_till' => '2026-05-30T21:59:59+0000',
                'dealer_id' => '11deC',
                'dealer' => ['name' => 'REMA 1000'],
                'source_strategy' => 'algolia_product_details_grouped_by_tjek_overlap',
                'fetched_product_offer_count' => 10,
            ],
            'offers' => array_map(fn (int $number): array => $this->productOffer($number), range(1, 10)),
        ];

        $paper = (new Rema1000PaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));

        $this->assertSame('weekly-paper', $paper->sourceExternalId);
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
        $this->assertSame('Brød', $offer->metadata['category_name']);
        $this->assertSame('2026-05-26T00:00:00+00:00', $offer->metadata['price_starts_at']);
    }

    public function test_it_rejects_rema_fixture_with_too_few_offers(): void
    {
        $payload = json_decode($this->fixture(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = array_slice($payload['offers'], 0, 9);

        $this->expectException(ScraperParseException::class);
        $this->expectExceptionMessage('REMA 1000 paper must contain at least 10 parsed offers.');

        (new Rema1000PaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/scrapers/rema1000/uge-23-combined.json'));
    }

    /**
     * @return array<string, mixed>
     */
    private function productOffer(int $number): array
    {
        return [
            'algolia' => [
                'id' => $number,
                'objectID' => (string) $number,
                'name' => "PRODUCT {$number}",
                'underline' => '200 GR. / REMA 1000',
                'hf2' => 'REMA 1000',
                'description_short' => "Varenummer: {$number}",
                'pricing' => ['price' => 10, 'price_per_unit' => '50.00 per Kg.', 'max_quantity' => 6],
                'images' => [['large' => "https://images.example/{$number}.webp"]],
                'department_id' => 10,
                'department_name' => 'Brød & Bavinchi',
                'category_id' => 655390,
                'category_name' => 'Brød',
            ],
            'product_detail' => [
                'id' => $number,
                'bar_codes' => ["570000{$number}"],
            ],
            'advertised_price' => [
                'price' => 10,
                'compare_unit_price' => 50,
                'max_quantity' => 6,
                'is_campaign' => true,
                'starting_at' => '2026-05-26T00:00:00+00:00',
                'ending_at' => '2026-05-30T00:00:00+00:00',
            ],
        ];
    }
}
