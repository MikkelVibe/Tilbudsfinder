<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationStatus;
use App\Models\CanonicalProduct;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\Paper;
use App\Models\PriceObservation;
use App\Models\ProductIdentifier;
use App\Models\ScrapedOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMatchingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_canonical_product_from_single_ean_offer(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $batch = ImportBatch::factory()->for($grocer)->create(['status' => ImportBatchStatus::Succeeded]);
        $paper = Paper::factory()->for($grocer)->for($batch)->create([
            'active_from' => '2026-05-26T00:00:00+00:00',
            'active_until' => '2026-05-30T00:00:00+00:00',
        ]);

        ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->create([
            'source_product_id' => '60055',
            'title' => 'LIMPAN',
            'price' => '10.00',
            'package_amount' => '900.000',
            'package_unit' => 'g',
            'compare_unit' => 'kg',
            'unit_price' => '11.11',
            'normalization_status' => NormalizationStatus::Succeeded,
            'source_payload' => $this->sourcePayload(['7311070338188']),
        ]);

        $this->artisan("products:match {$batch->id}")
            ->expectsOutput('Product matching completed.')
            ->expectsOutput('Matched: 1')
            ->expectsOutput('Ambiguous: 0')
            ->expectsOutput('Skipped: 0')
            ->expectsOutput('Conflicts: 0')
            ->assertSuccessful();

        $product = CanonicalProduct::query()->firstOrFail();

        $this->assertSame('LIMPAN', $product->name);
        $this->assertSame('PÅGEN', $product->brand);
        $this->assertSame('900.000', $product->package_amount);
        $this->assertSame('251.00', $product->energy_kcal_per_100);
        $this->assertSame('7.40', $product->protein_g_per_100);
        $this->assertSame('1.00', $product->salt_g_per_100);
        $this->assertSame('g', $product->nutrition_basis_unit);

        $this->assertSame(2, ProductIdentifier::query()->count());
        $this->assertDatabaseHas('product_identifiers', ['type' => 'ean', 'value' => '7311070338188', 'grocer_id' => null]);
        $this->assertDatabaseHas('product_identifiers', ['type' => 'source_product_id', 'value' => '60055', 'grocer_id' => $grocer->id]);
        $this->assertDatabaseHas('product_matches', ['canonical_product_id' => $product->id, 'status' => 'matched', 'confidence' => 100]);
        $this->assertSame(1, PriceObservation::query()->count());
    }

    public function test_it_attaches_multiple_barcodes_to_one_canonical_product(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $batch = ImportBatch::factory()->for($grocer)->create(['status' => ImportBatchStatus::Succeeded]);
        $paper = Paper::factory()->for($grocer)->for($batch)->create();

        ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->create([
            'source_payload' => $this->sourcePayload(['7311070338188', '7311070338195']),
        ]);

        $this->artisan("products:match {$batch->id}")
            ->expectsOutput('Matched: 1')
            ->expectsOutput('Ambiguous: 0')
            ->assertSuccessful();

        $product = CanonicalProduct::query()->firstOrFail();

        $this->assertSame(1, CanonicalProduct::query()->count());
        $this->assertSame(1, PriceObservation::query()->count());
        $this->assertSame(3, ProductIdentifier::query()->count());
        $this->assertDatabaseHas('product_identifiers', ['canonical_product_id' => $product->id, 'type' => 'ean', 'value' => '7311070338188']);
        $this->assertDatabaseHas('product_identifiers', ['canonical_product_id' => $product->id, 'type' => 'ean', 'value' => '7311070338195']);
        $this->assertDatabaseHas('product_matches', ['status' => 'matched', 'canonical_product_id' => $product->id]);
    }

    /**
     * @param  list<string>  $barcodes
     * @return array<string, mixed>
     */
    private function sourcePayload(array $barcodes): array
    {
        return [
            'catalog_product' => [
                'id' => 60055,
                'name' => 'LIMPAN',
                'hf2' => 'PÅGEN',
                'declaration' => 'Ingredienser',
                'bar_codes' => $barcodes,
                'nutrition_info' => [
                    ['name' => 'Energi', 'value' => '1.061 KJ / 251 kcal', 'sort' => '1'],
                    ['name' => 'Fedt', 'value' => '2,2', 'sort' => '2'],
                    ['name' => 'Heraf mættede fedtsyrer', 'value' => '0,4', 'sort' => '3'],
                    ['name' => 'Kulhydrat', 'value' => '49', 'sort' => '4'],
                    ['name' => 'Heraf sukkerarter', 'value' => '3,2', 'sort' => '5'],
                    ['name' => 'Kostfibre', 'value' => '2,6', 'sort' => '6'],
                    ['name' => 'Protein', 'value' => '7,4', 'sort' => '7'],
                    ['name' => 'Salt', 'value' => '1,0', 'sort' => '8'],
                ],
            ],
            'product_detail' => [
                'id' => 60055,
                'bar_codes' => $barcodes,
            ],
        ];
    }
}
