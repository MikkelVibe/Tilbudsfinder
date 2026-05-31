<?php

namespace Tests\Feature\Scrapers;

use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationStatus;
use App\Imports\ImportPersistencePipeline;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Scrapers\Coop\CoopTjekPaperParser;
use App\Scrapers\Exceptions\ScraperParseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoopTjekPaperParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_coop_tjek_payload_into_paper_input(): void
    {
        $paper = (new CoopTjekPaperParser)->parse($this->payload());

        $this->assertSame('coop-weekly-paper', $paper->sourceExternalId);
        $this->assertSame('Uge 23', $paper->title);
        $this->assertSame('2026-05-28 22:00:00', $paper->activeFrom->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-04 21:59:59', $paper->activeUntil->format('Y-m-d H:i:s'));
        $this->assertCount(12, $paper->offers);
        $this->assertSame('Kvickly', $paper->metadata['dealer_name']);
        $this->assertSame(281, $paper->metadata['offer_count']);
        $this->assertSame(12, $paper->metadata['fetched_offer_count']);
        $this->assertSame(269, $paper->metadata['offer_count_mismatch']);
        $this->assertSame(1, $paper->metadata['incito_enriched_offer_count']);

        $offer = $paper->offers[0];

        $this->assertSame('Cirkel Kaffe, Gevalia eller Cafe Noir formalet kaffe', $offer->title);
        $this->assertSame(49, $offer->price);
        $this->assertSame('Note: Maks. 3 | Flere varianter. 400-500 g. Kg-pris maks. 122,50. Frit valg. 1 stk. Maks. 3 stk. pr. kunde 400-500 g', $offer->packageText);
        $this->assertSame('122,50', $offer->sourceUnitPrice);
        $this->assertSame('Maks. 3', $offer->purchaseLimitText);
        $this->assertSame('coop-offer-1', $offer->sourceOfferId);
        $this->assertSame('https://images.example/coop/1.webp', $offer->imageUrl);
        $this->assertSame(2, $offer->metadata['catalog_page']);
        $this->assertSame(2, $offer->metadata['incito_product_count']);
    }

    public function test_it_persists_parsed_coop_tjek_payload_through_import_pipeline(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'kvickly']);
        $paper = (new CoopTjekPaperParser)->parse($this->payload());

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paper);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(12, $batch->parsed_offer_count);
        $this->assertSame(12, $batch->published_offer_count);
        $this->assertTrue(Storage::disk('local')->exists($batch->raw_payload_path));

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->where('source_external_id', 'coop-weekly-paper')->count());
        $this->assertSame(12, ScrapedOffer::query()->count());

        $butter = ScrapedOffer::query()->where('source_offer_id', 'coop-offer-2')->firstOrFail();

        $this->assertSame('Kærgården smørbar', $butter->title);
        $this->assertSame('20.00', $butter->price);
        $this->assertSame('200.000', $butter->package_amount);
        $this->assertSame('kg', $butter->compare_unit);
        $this->assertSame('100.00', $butter->unit_price);
        $this->assertSame(NormalizationStatus::Succeeded, $butter->normalization_status);

        $coffee = ScrapedOffer::query()->where('source_offer_id', 'coop-offer-1')->firstOrFail();

        $this->assertSame('5700000000011', $coffee->source_payload['_incito_enrichment']['products'][0]['id']);
        $this->assertSame('COFFEE VARIANT 400 G', $coffee->source_payload['_incito_enrichment']['products'][0]['title']);
    }

    public function test_it_rejects_coop_tjek_payload_with_too_few_offers(): void
    {
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = array_slice($payload['offers'], 0, 9);

        $this->expectException(ScraperParseException::class);
        $this->expectExceptionMessage('COOP Tjek paper must contain at least 10 parsed offers.');

        (new CoopTjekPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function payload(): string
    {
        return json_encode([
            'catalog' => [
                'id' => 'coop-weekly-paper',
                'label' => 'Uge 23',
                'run_from' => '2026-05-28T22:00:00+0000',
                'run_till' => '2026-06-04T21:59:59+0000',
                'offer_count' => 281,
                'fetched_offer_count' => 12,
                'offer_count_mismatch' => 269,
                'incito_enriched_offer_count' => 1,
                'page_count' => 68,
                'pdf_url' => 'https://squid-api.tjek.com/v2/catalogs/coop-weekly-paper/download',
                'source_strategy' => 'tjek_coop_weekly_catalog_offers',
                'source_url' => 'https://kvickly.coop.dk/tilbudsavis/',
                'dealer_id' => 'c1edq',
                'dealer' => ['name' => 'Kvickly'],
            ],
            'offers' => [
                $this->offer(1, 'Cirkel Kaffe, Gevalia eller Cafe Noir formalet kaffe', 'Note: Maks. 3 | Flere varianter. 400-500 g. Kg-pris maks. 122,50. Frit valg. 1 stk. Maks. 3 stk. pr. kunde', 49, 2, 400, 500),
                $this->offer(2, 'Kærgården smørbar', '200 g. Kg-pris 100,00. Frit valg. 1 pakke.', 20, 15, 200),
                ...array_map(fn (int $number): array => $this->offer($number, "COOP Product {$number}", '200 g. Kg-pris 50,00. Frit valg. 1 stk.', 10, $number, 200), range(3, 12)),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(int $number, string $heading, string $description, int $price, int $catalogPage, int $from, ?int $to = null): array
    {
        return [
            'id' => "coop-offer-{$number}",
            'heading' => $heading,
            'description' => $description,
            'catalog_page' => $catalogPage,
            'pricing' => ['price' => $price, 'currency' => 'DKK'],
            'quantity' => [
                'unit' => ['symbol' => 'g'],
                'size' => ['from' => $from, 'to' => $to ?? $from],
                'pieces' => ['from' => 1, 'to' => 1],
            ],
            'images' => ['zoom' => "https://images.example/coop/{$number}.webp"],
            'catalog_id' => 'coop-weekly-paper',
            '_incito_enrichment' => $number === 1 ? [
                'offer_id' => 'incito-offer-1',
                'title' => $heading,
                'quantity' => '1 stk.',
                'products' => [[
                    'id' => '5700000000011',
                    'title' => 'COFFEE VARIANT 400 G',
                    'image' => 'https://images.example/incito/coffee-400.webp',
                ], [
                    'id' => '5700000000028',
                    'title' => 'COFFEE VARIANT 500 G',
                    'image' => 'https://images.example/incito/coffee-500.webp',
                ]],
            ] : null,
        ];
    }
}
