<?php

namespace Tests\Feature\Scrapers;

use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationStatus;
use App\Imports\ImportPersistencePipeline;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Scrapers\Dagrofa\DagrofaPaperParser;
use App\Scrapers\Exceptions\ScraperParseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DagrofaPaperParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_dagrofa_payload_into_paper_input(): void
    {
        $paper = (new DagrofaPaperParser)->parse($this->payload());

        $this->assertSame('spar-2026-05-31', $paper->sourceExternalId);
        $this->assertSame('SPAR aktuelle tilbud 2026-05-31', $paper->title);
        $this->assertCount(10, $paper->offers);

        $offer = $paper->offers[0];

        $this->assertSame('KYLLINGEBRYSTFILET', $offer->title);
        $this->assertSame(25, $offer->price);
        $this->assertSame('450 gram', $offer->packageText);
        $this->assertSame('1001', $offer->sourceProductId);
        $this->assertSame(35, $offer->metadata['normal_price']);
    }

    public function test_it_persists_dagrofa_payload_through_import_pipeline(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'spar']);
        $paper = (new DagrofaPaperParser)->parse($this->payload());

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paper);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(10, $batch->parsed_offer_count);
        $this->assertSame(10, $batch->published_offer_count);
        $this->assertSame(0, $batch->normalization_failure_count);
        $this->assertTrue(Storage::disk('local')->exists($batch->raw_payload_path));

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->where('source_external_id', 'spar-2026-05-31')->count());
        $this->assertSame(10, ScrapedOffer::query()->count());

        $offer = ScrapedOffer::query()->where('source_product_id', '1001')->firstOrFail();

        $this->assertSame('KYLLINGEBRYSTFILET', $offer->title);
        $this->assertSame('25.00', $offer->price);
        $this->assertSame('450.000', $offer->package_amount);
        $this->assertSame('kg', $offer->compare_unit);
        $this->assertSame(NormalizationStatus::Succeeded, $offer->normalization_status);
    }

    public function test_it_rejects_payload_with_too_few_offers(): void
    {
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = array_slice($payload['offers'], 0, 9);

        $this->expectException(ScraperParseException::class);
        $this->expectExceptionMessage('Dagrofa paper must contain at least 10 parsed offers.');

        (new DagrofaPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function test_it_uses_direct_quantity_summary_without_title_counts(): void
    {
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'][0]['productDisplayName'] = 'Flødeboller M/Kokos 4 Stk';
        $payload['offers'][0]['summary'] = '124 Gram';
        $payload['offers'][0]['discountPrice'] = 22;

        $paper = (new DagrofaPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
        $offer = $paper->offers[0];

        $this->assertSame('Flødeboller M/Kokos 4 Stk', $offer->title);
        $this->assertSame('124 Gram', $offer->packageText);
    }

    private function payload(): string
    {
        return json_encode([
            'catalog' => [
                'id' => 'spar-2026-05-31',
                'label' => 'SPAR aktuelle tilbud 2026-05-31',
                'run_from' => '2026-05-31T00:00:00+00:00',
                'run_till' => '2026-05-31T23:59:59+00:00',
                'dealer_id' => '1222',
                'dealer' => ['name' => 'SPAR'],
                'source_url' => 'https://spar.dk/',
                'source_strategy' => 'dagrofa_longjohn_discount_products',
                'fetched_offer_count' => 10,
            ],
            'offers' => array_map(fn (int $number): array => $this->offer($number), range(1, 10)),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(int $number): array
    {
        return [
            'id' => (string) (1000 + $number),
            'sku' => (string) (1000 + $number),
            'productDisplayName' => $number === 1 ? 'KYLLINGEBRYSTFILET' : "PRODUCT {$number}",
            'summary' => $number === 1 ? "Kyllingebryst\r\n\r\nBrutto vægt: 462 gram\r\n\r\nNetto vægt: 450 gram" : "Test\r\n\r\nNetto vægt: 200 gram",
            'price' => $number === 1 ? 35 : 20,
            'discountPrice' => $number === 1 ? 25 : 10,
            'discountAmount' => 1,
            'highResImg' => "https://images.example/{$number}.jpg",
            'categoryId' => 12,
            'advertisementProduct' => true,
        ];
    }
}
