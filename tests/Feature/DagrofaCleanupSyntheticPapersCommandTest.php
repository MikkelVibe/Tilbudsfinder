<?php

namespace Tests\Feature;

use App\Enums\ImportBatchStatus;
use App\Models\CanonicalProduct;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\OfferSearchDocument;
use App\Models\Paper;
use App\Models\PriceObservation;
use App\Models\ScrapedOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DagrofaCleanupSyntheticPapersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_synthetic_dagrofa_paper_consolidation_without_deleting_them(): void
    {
        Storage::fake('local');

        $syntheticPaper = $this->syntheticDagrofaPaper('spar');
        $this->syntheticDagrofaPaper('spar', '2026-06-21');
        $this->realDagrofaPaper('spar');
        $this->syntheticNonDagrofaPaper();

        $this->artisan('dagrofa:cleanup-synthetic-papers')
            ->expectsOutput('Matched synthetic Dagrofa papers: 2')
            ->expectsOutput('Inferred historical Dagrofa avis groups: 1')
            ->expectsOutput('Representative papers to keep: 1')
            ->expectsOutput('Duplicate synthetic papers to delete: 1')
            ->expectsOutput('Duplicate import batches to delete: 1')
            ->expectsOutput('Matched scraped offers: 2')
            ->expectsOutput('Matched search documents: 2')
            ->expectsOutput('Duplicate raw payload files to delete: 1')
            ->expectsOutput('Dry run only. Re-run with --execute to consolidate these records.')
            ->assertSuccessful();

        $this->assertModelExists($syntheticPaper);
        Storage::disk('local')->assertExists($syntheticPaper->importBatch->raw_payload_path);
    }

    public function test_execute_keeps_one_representative_synthetic_dagrofa_paper_per_period(): void
    {
        Storage::fake('local');

        $smallerSyntheticPaper = $this->syntheticDagrofaPaper('minkobmand', '2026-06-19', 10);
        $keeperSyntheticPaper = $this->syntheticDagrofaPaper('minkobmand', '2026-06-20', 20);
        $smallerSyntheticBatch = $smallerSyntheticPaper->importBatch;
        $smallerSyntheticOffer = $smallerSyntheticPaper->scrapedOffers()->firstOrFail();
        $realDagrofaPaper = $this->realDagrofaPaper('minkobmand');
        $nonDagrofaPaper = $this->syntheticNonDagrofaPaper();

        $this->artisan('dagrofa:cleanup-synthetic-papers --execute')
            ->expectsOutput('Matched synthetic Dagrofa papers: 2')
            ->expectsOutput('Consolidated historical Dagrofa avis groups: 1')
            ->expectsOutput('Deleted duplicate synthetic Dagrofa papers: 1')
            ->expectsOutput('Deleted import batches with no remaining papers: 1')
            ->assertSuccessful();

        $this->assertModelMissing($smallerSyntheticPaper);
        $this->assertModelMissing($smallerSyntheticBatch);
        $this->assertModelExists($smallerSyntheticOffer);
        $this->assertModelExists($keeperSyntheticPaper);
        $this->assertModelExists($realDagrofaPaper);
        $this->assertModelExists($nonDagrofaPaper);
        Storage::disk('local')->assertMissing($smallerSyntheticBatch->raw_payload_path);

        $keeperSyntheticPaper->refresh();
        $smallerSyntheticOffer->refresh();

        $this->assertSame('2026-06-19 00:00:00', $keeperSyntheticPaper->active_from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-20 23:59:59', $keeperSyntheticPaper->active_until->format('Y-m-d H:i:s'));
        $this->assertSame($keeperSyntheticPaper->id, $smallerSyntheticOffer->paper_id);
        $this->assertSame($keeperSyntheticPaper->import_batch_id, $smallerSyntheticOffer->import_batch_id);
        $this->assertSame(30, $keeperSyntheticPaper->scrapedOffers()->count());
        $this->assertSame(30, $keeperSyntheticPaper->importBatch->refresh()->published_offer_count);
        $this->assertSame($keeperSyntheticPaper->id, OfferSearchDocument::query()->where('scraped_offer_id', $smallerSyntheticOffer->id)->value('paper_id'));
        $priceObservation = PriceObservation::query()->where('scraped_offer_id', $smallerSyntheticOffer->id)->firstOrFail();
        $this->assertSame('2026-06-19 00:00:00', $priceObservation->valid_from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-20 23:59:59', $priceObservation->valid_until->format('Y-m-d H:i:s'));
        $this->assertSame([
            'minkobmand-2026-06-19',
            'minkobmand-2026-06-20',
        ], $keeperSyntheticPaper->importBatch->refresh()->metadata['synthetic_consolidated_from']);
    }

    private function syntheticDagrofaPaper(string $slug, string $date = '2026-06-20', int $offerCount = 1): Paper
    {
        $grocer = Grocer::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => strtoupper($slug), 'is_enabled' => true],
        );
        $batch = ImportBatch::factory()->for($grocer)->create([
            'status' => ImportBatchStatus::Succeeded,
            'source_external_id' => "{$slug}-{$date}",
            'raw_payload_path' => "imports/raw/{$slug}/2026/06/{$date}.json",
            'metadata' => ['source_strategy' => 'dagrofa_longjohn_discount_products'],
            'published_offer_count' => $offerCount,
        ]);
        $paper = Paper::factory()->for($grocer)->for($batch)->create([
            'source_external_id' => "{$slug}-{$date}",
            'active_from' => "{$date}T00:00:00+00:00",
            'active_until' => "{$date}T23:59:59+00:00",
        ]);

        foreach (range(1, $offerCount) as $index) {
            $offer = ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->create([
                'source_offer_id' => "{$date}-{$index}",
                'source_product_id' => "{$date}-{$index}",
            ]);

            $this->createSearchDocument($grocer, $paper, $offer);
        }

        Storage::disk('local')->put($batch->raw_payload_path, '{}');

        return $paper->refresh();
    }

    private function realDagrofaPaper(string $slug): Paper
    {
        $grocer = Grocer::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => strtoupper($slug), 'is_enabled' => true],
        );
        $batch = ImportBatch::factory()->for($grocer)->create([
            'status' => ImportBatchStatus::Succeeded,
            'metadata' => ['source_strategy' => 'dagrofa_longjohn_discount_products'],
        ]);

        return Paper::factory()->for($grocer)->for($batch)->create([
            'source_external_id' => 'ebb4a0c3-2124-43de-9612-f4dab0797088',
            'active_from' => '2026-06-19T00:00:00+00:00',
            'active_until' => '2026-06-25T23:59:59+00:00',
        ]);
    }

    private function syntheticNonDagrofaPaper(): Paper
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $batch = ImportBatch::factory()->for($grocer)->create([
            'status' => ImportBatchStatus::Succeeded,
            'metadata' => ['source_strategy' => 'dagrofa_longjohn_discount_products'],
        ]);

        return Paper::factory()->for($grocer)->for($batch)->create([
            'source_external_id' => 'rema1000-2026-06-20',
            'active_from' => '2026-06-20T00:00:00+00:00',
            'active_until' => '2026-06-20T23:59:59+00:00',
        ]);
    }

    private function createSearchDocument(Grocer $grocer, Paper $paper, ScrapedOffer $offer): void
    {
        OfferSearchDocument::create([
            'scraped_offer_id' => $offer->id,
            'grocer_id' => $grocer->id,
            'paper_id' => $paper->id,
            'grocer_slug' => $grocer->slug,
            'grocer_name' => $grocer->name,
            'title' => $offer->title,
            'search_text' => $offer->title,
            'price' => $offer->price,
            'currency' => 'DKK',
            'active_from' => $paper->active_from,
            'active_until' => $paper->active_until,
        ]);

        PriceObservation::create([
            'canonical_product_id' => CanonicalProduct::create(['name' => $offer->title])->id,
            'scraped_offer_id' => $offer->id,
            'grocer_id' => $grocer->id,
            'price' => $offer->price,
            'currency' => 'DKK',
            'observed_at' => now(),
            'valid_from' => $paper->active_from,
            'valid_until' => $paper->active_until,
        ]);
    }
}
