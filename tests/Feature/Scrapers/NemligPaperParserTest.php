<?php

namespace Tests\Feature\Scrapers;

use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationStatus;
use App\Imports\ImportPersistencePipeline;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Scrapers\Exceptions\ScraperParseException;
use App\Scrapers\Nemlig\NemligPaperParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NemligPaperParserTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_parses_nemlig_payload_into_paper_input(): void
    {
        $paper = (new NemligPaperParser)->parse($this->payload());

        $this->assertSame('nemlig-2026-05-31-2026-06-07', $paper->sourceExternalId);
        $this->assertSame('Nemlig tilbud', $paper->title);
        $this->assertCount(10, $paper->offers);

        $offer = $paper->offers[0];

        $this->assertSame('Honningtomater i kasse', $offer->title);
        $this->assertSame(60, $offer->price);
        $this->assertSame('1,5 kg', $offer->packageText);
        $this->assertSame(40, $offer->sourceUnitPrice);
        $this->assertSame('5070890-US', $offer->sourceOfferId);
        $this->assertSame('Skarp pris', $offer->metadata['product_group_heading']);
        $this->assertSame('12', $offer->metadata['nutrition']['energy_kcal']);
        $this->assertSame('0,7', $offer->metadata['nutrition']['protein']);
        $this->assertSame('Danmark', $offer->metadata['origin_code_description']);
        $this->assertSame('VKN-1', $offer->metadata['vk_number']);
    }

    public function test_it_persists_nemlig_payload_through_import_pipeline(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'nemlig']);
        $paper = (new NemligPaperParser)->parse($this->payload());

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paper);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(10, $batch->parsed_offer_count);
        $this->assertSame(10, $batch->published_offer_count);
        $this->assertSame(0, $batch->normalization_failure_count);
        $this->assertTrue(Storage::disk('local')->exists($batch->raw_payload_path));

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->where('source_external_id', 'nemlig-2026-05-31-2026-06-07')->count());
        $this->assertSame(10, ScrapedOffer::query()->count());

        $offer = ScrapedOffer::query()->where('source_product_id', '5070890')->firstOrFail();

        $this->assertSame('Honningtomater i kasse', $offer->title);
        $this->assertSame('60.00', $offer->price);
        $this->assertSame('1.500', $offer->package_amount);
        $this->assertSame('kg', $offer->compare_unit);
        $this->assertSame(NormalizationStatus::Succeeded, $offer->normalization_status);
    }

    public function test_it_extracts_package_text_from_later_nemlig_description_segments(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'nemlig']);
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'][0] = [
            ...$payload['offers'][0],
            'Id' => '5065739',
            'Name' => 'P.Maufoux Macon Villages',
            'Category' => 'VIN',
            'SubCategory' => 'Kraftig - tør',
            'UnitPrice' => '199,95 kr./Stk.',
            'UnitPriceCalc' => 199.95,
            'UnitPriceLabel' => 'kr./Stk.',
            'Description' => 'Frankrig / Bourgogne / 2024 / 0,75 l / hvidvin',
            'Price' => 199.95,
            'Campaign' => [
                ...$payload['offers'][0]['Campaign'],
                'DiscountSavings' => 100,
                'CampaignPrice' => 99.95,
                'CampaignUnitPrice' => 99.95,
                'Code' => 'P',
            ],
        ];

        $paper = (new NemligPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertSame('0,75 l', $paper->offers[0]->packageText);

        (new ImportPersistencePipeline)->persist($grocer, $paper);

        $offer = ScrapedOffer::query()->where('source_product_id', '5065739')->firstOrFail();

        $this->assertSame(NormalizationStatus::Succeeded, $offer->normalization_status);
        $this->assertSame('0.750', $offer->package_amount);
        $this->assertSame('l', $offer->compare_unit);
        $this->assertSame('133.27', $offer->unit_price);
    }

    public function test_it_prefers_package_segment_matching_nemlig_unit_price_label(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'nemlig']);
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'][0] = [
            ...$payload['offers'][0],
            'Id' => '5070713',
            'Name' => 'Ananas ispinde',
            'Category' => 'Frost',
            'UnitPrice' => '59,90 kr./Ltr.',
            'UnitPriceCalc' => 59.90,
            'UnitPriceLabel' => 'kr./Ltr.',
            'Description' => '10 stk. / 0,50 l / frost / Gestus',
            'Price' => 39.95,
            'Campaign' => [
                ...$payload['offers'][0]['Campaign'],
                'CampaignPrice' => 23.96,
                'CampaignUnitPrice' => 47.92,
                'Code' => 'U',
            ],
        ];

        $paper = (new NemligPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertSame('0,50 l', $paper->offers[0]->packageText);

        (new ImportPersistencePipeline)->persist($grocer, $paper);

        $offer = ScrapedOffer::query()->where('source_product_id', '5070713')->firstOrFail();

        $this->assertSame(NormalizationStatus::Succeeded, $offer->normalization_status);
        $this->assertSame('0.500', $offer->package_amount);
        $this->assertSame('l', $offer->compare_unit);
        $this->assertSame('47.92', $offer->unit_price);
    }

    public function test_it_keeps_nemlig_multi_buy_offers_out_of_unit_price_comparison(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'nemlig']);
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'][0] = [
            ...$payload['offers'][0],
            'Id' => '5602556',
            'Name' => 'Chicken popsters',
            'Category' => 'Frost',
            'UnitPrice' => '70,95 kr./Kg.',
            'UnitPriceCalc' => 70.95,
            'UnitPriceLabel' => 'kr./Kg.',
            'Description' => '1,0 kg / frost / Kitchen Joy',
            'Price' => 77.95,
            'Campaign' => [
                ...$payload['offers'][0]['Campaign'],
                'MinQuantity' => 2,
                'TotalPrice' => 100,
                'VariousPriceProductsCampaign' => true,
                'CampaignPrice' => 100,
                'CampaignUnitPrice' => null,
                'Type' => 'ProductCampaignMixOffer',
                'Code' => 'U',
            ],
        ];

        $paper = (new NemligPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        (new ImportPersistencePipeline)->persist($grocer, $paper);

        $offer = ScrapedOffer::query()->where('source_product_id', '5602556')->firstOrFail();

        $this->assertSame(NormalizationStatus::Partial, $offer->normalization_status);
        $this->assertNull($offer->package_amount);
        $this->assertNull($offer->compare_unit);
        $this->assertNull($offer->unit_price);
    }

    public function test_it_ignores_normal_unit_price_when_campaign_unit_price_is_missing(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'nemlig']);
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'][0] = [
            ...$payload['offers'][0],
            'Id' => '5052676',
            'Name' => 'Blåmuslinger',
            'Category' => 'Kød & fisk',
            'UnitPrice' => '39,95 kr./Kg.',
            'UnitPriceCalc' => 39.95,
            'UnitPriceLabel' => 'kr./Kg.',
            'Description' => '1 kg / Vilsund Blue',
            'Price' => 39.95,
            'Campaign' => [
                ...$payload['offers'][0]['Campaign'],
                'CampaignPrice' => 82.50,
                'CampaignUnitPrice' => null,
                'Type' => 'ProductCampaignDiscount',
                'Code' => 'U',
            ],
        ];

        $paper = (new NemligPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        (new ImportPersistencePipeline)->persist($grocer, $paper);

        $offer = ScrapedOffer::query()->where('source_product_id', '5052676')->firstOrFail();

        $this->assertSame(NormalizationStatus::Succeeded, $offer->normalization_status);
        $this->assertSame('1.000', $offer->package_amount);
        $this->assertSame('kg', $offer->compare_unit);
        $this->assertSame('82.50', $offer->unit_price);
    }

    public function test_it_rejects_payload_with_too_few_offers(): void
    {
        $payload = json_decode($this->payload(), true, flags: JSON_THROW_ON_ERROR);
        $payload['offers'] = array_slice($payload['offers'], 0, 9);

        $this->expectException(ScraperParseException::class);
        $this->expectExceptionMessage('Nemlig paper must contain at least 10 parsed offers.');

        (new NemligPaperParser)->parse(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function payload(): string
    {
        return json_encode([
            'catalog' => [
                'id' => 'nemlig-2026-05-31-2026-06-07',
                'label' => 'Nemlig tilbud',
                'run_from' => '2026-05-31T22:00:00+00:00',
                'run_till' => '2026-06-07T21:59:59+00:00',
                'dealer' => ['name' => 'Nemlig'],
                'source_url' => 'https://www.nemlig.com/tilbud',
                'source_strategy' => 'nemlig_product_groups',
                'fetched_offer_count' => 10,
                'groups' => [[
                    'heading' => 'Skarp pris',
                    'product_group_id' => 'group-1',
                    'total_products' => 56,
                    'fetched_products' => 10,
                ]],
            ],
            'offers' => array_map(fn (int $number): array => $this->offer($number), range(1, 10)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(int $number): array
    {
        return [
            'Id' => $number === 1 ? '5070890' : (string) (5070890 + $number),
            'Name' => $number === 1 ? 'Honningtomater i kasse' : "Nemlig Product {$number}",
            'Category' => 'Grønt',
            'SubCategory' => 'Agurk / Tomat',
            'PrimaryImage' => "https://www.nemlig.com/images/{$number}.jpg",
            'UnitPrice' => '40,00 kr./Kg.',
            'UnitPriceCalc' => 40,
            'UnitPriceLabel' => 'kr./Kg.',
            'Description' => '1,5 kg / Holland / Klasse 1',
            'Price' => 110,
            'Campaign' => [
                'DiscountSavings' => 50,
                'MaxQuantity' => $number === 1 ? 6 : 0,
                'CampaignPrice' => 60,
                'CampaignUnitPrice' => 40,
                'Type' => 'ProductCampaignDiscount',
                'Code' => 'US',
                'IntervalStart' => '2026-05-31T22:00:00Z',
                'IntervalEnd' => '2026-06-07T21:59:59Z',
            ],
            '_nemlig_group' => [
                'Heading' => 'Skarp pris',
                'ProductGroupId' => 'group-1',
            ],
            '_nemlig_detail' => [
                'Declarations' => [
                    'ShowDeclarations' => true,
                    'EnergyKcal' => '12',
                    'EnergyKj' => '51',
                    'NutritionalContentProtein' => '0,7',
                    'NutritionalContentFat' => '0,1',
                    'NutritionalContentCarbohydrate' => '2,1',
                    'SaturatedFattyAcid' => '0',
                    'Sugar' => '1,9',
                    'Salt' => '0,01',
                    'DietaryFiber' => '1,2',
                ],
                'Attributes' => [[
                    'Key' => 'Oprindelsesland',
                    'Value' => ['Danmark'],
                ]],
                'Traceability' => ['LotNumber' => 'ABC'],
                'TechnicalDescription' => 'Detail text',
                'OriginCodeDescription' => 'Danmark',
                'VkNumber' => 'VKN-1',
                'Text' => 'Product text',
            ],
        ];
    }
}
