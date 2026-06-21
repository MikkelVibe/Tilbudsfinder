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

class NemligRegroupIntervalPapersCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_legacy_nemlig_interval_regrouping_without_changes(): void
    {
        Storage::fake('local');

        $paper = $this->legacyNemligPaper();
        $this->visibleOffer($paper);
        $this->hiddenOffer($paper);
        $this->nonNemligPaper();

        $this->artisan('nemlig:regroup-interval-papers')
            ->expectsOutput('Matched legacy Nemlig papers: 1')
            ->expectsOutput('Visible interval offers to keep: 1')
            ->expectsOutput('Hidden or irrelevant interval offers to discard: 1')
            ->expectsOutput('Invalid interval offers to discard: 0')
            ->expectsOutput('Interval papers to create or reuse: 1')
            ->expectsOutput('Old papers that would become empty: 1')
            ->expectsOutput('Dry run only. Re-run with --execute to regroup these records.')
            ->assertSuccessful();

        $this->assertModelExists($paper);
        $this->assertSame(2, ScrapedOffer::query()->where('paper_id', $paper->id)->count());
        $this->assertSame(2, OfferSearchDocument::query()->count());
    }

    public function test_execute_moves_visible_offers_to_interval_papers_and_discards_hidden_offers(): void
    {
        Storage::fake('local');

        $paper = $this->legacyNemligPaper();
        $visibleOffer = $this->visibleOffer($paper);
        $hiddenOffer = $this->hiddenOffer($paper);
        $batch = $paper->importBatch;

        $this->artisan('nemlig:regroup-interval-papers --execute')
            ->expectsOutput('Matched legacy Nemlig papers: 1')
            ->expectsOutput('Regrouped legacy Nemlig papers: 1')
            ->expectsOutput('Moved visible interval offers: 1')
            ->expectsOutput('Discarded hidden, invalid, irrelevant, or duplicate interval offers: 1')
            ->expectsOutput('Created interval papers: 1')
            ->expectsOutput('Deleted old empty papers: 1')
            ->assertSuccessful();

        $this->assertModelMissing($paper);
        $this->assertModelExists($batch);
        $this->assertModelExists($visibleOffer);
        $this->assertModelMissing($hiddenOffer);

        $intervalPaper = Paper::query()->where('source_external_id', 'nemlig-20260531220000-20260607215959')->firstOrFail();
        $visibleOffer->refresh();

        $this->assertSame($intervalPaper->id, $visibleOffer->paper_id);
        $this->assertSame('2026-05-31 22:00:00', $intervalPaper->active_from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-07 21:59:59', $intervalPaper->active_until->format('Y-m-d H:i:s'));
        $this->assertSame(1, $batch->refresh()->published_offer_count);
        $this->assertSame(1, $batch->refresh()->parsed_offer_count);
        $this->assertSame(0, $batch->refresh()->normalization_failure_count);

        $searchDocument = OfferSearchDocument::query()->where('scraped_offer_id', $visibleOffer->id)->firstOrFail();
        $this->assertSame($intervalPaper->id, $searchDocument->paper_id);
        $this->assertSame('2026-05-31 22:00:00', $searchDocument->active_from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-07 21:59:59', $searchDocument->active_until->format('Y-m-d H:i:s'));

        $observation = PriceObservation::query()->where('scraped_offer_id', $visibleOffer->id)->firstOrFail();
        $this->assertSame('2026-05-31 22:00:00', $observation->valid_from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-07 21:59:59', $observation->valid_until->format('Y-m-d H:i:s'));
        $this->assertSame(1, OfferSearchDocument::query()->count());
    }

    public function test_execute_consolidates_duplicate_interval_papers_and_deduplicates_offers(): void
    {
        Storage::fake('local');

        $firstPaper = $this->legacyNemligPaper('nemlig-first-20260531220000-20260607215959');
        $secondPaper = $this->legacyNemligPaper('nemlig-second-20260531220000-20260607215959');
        $firstOffer = $this->visibleOffer($firstPaper, '5070001');
        $secondOffer = $this->visibleOffer($secondPaper, '5070001');

        $this->artisan('nemlig:regroup-interval-papers --execute')
            ->expectsOutput('Matched legacy Nemlig papers: 2')
            ->expectsOutput('Created interval papers: 1')
            ->assertSuccessful();

        $intervalPaper = Paper::query()->where('source_external_id', 'nemlig-20260531220000-20260607215959')->firstOrFail();

        $this->assertModelExists($firstOffer);
        $this->assertModelMissing($secondOffer);
        $this->assertSame($intervalPaper->id, $firstOffer->refresh()->paper_id);
        $this->assertSame(1, ScrapedOffer::query()->where('paper_id', $intervalPaper->id)->count());
    }

    private function legacyNemligPaper(string $sourceExternalId = 'nemlig-legacy'): Paper
    {
        $grocer = Grocer::query()->firstOrCreate(['slug' => 'nemlig'], ['name' => 'Nemlig']);
        $batch = ImportBatch::factory()->for($grocer)->create([
            'status' => ImportBatchStatus::Succeeded,
            'source_external_id' => $sourceExternalId,
            'raw_payload_path' => "imports/raw/nemlig/2026/06/{$sourceExternalId}.json",
            'metadata' => ['source_strategy' => 'nemlig_product_groups'],
            'parsed_offer_count' => 2,
            'published_offer_count' => 2,
            'normalization_failure_count' => 0,
        ]);

        Storage::disk('local')->put($batch->raw_payload_path, '{}');

        return Paper::factory()->for($grocer)->for($batch)->create([
            'source_external_id' => $sourceExternalId,
            'active_from' => '2024-05-01T22:00:00+00:00',
            'active_until' => '2026-07-26T21:59:59+00:00',
        ]);
    }

    private function visibleOffer(Paper $paper, string $sourceProductId = 'visible-product'): ScrapedOffer
    {
        return $this->offer($paper, 'Visible Nemlig Product', [
            'Category' => 'Grønt',
            'SubCategory' => 'Agurk / Tomat',
            'Campaign' => [
                'CampaignPrice' => 60,
                'IntervalStart' => '2026-05-31T22:00:00Z',
                'IntervalEnd' => '2026-06-07T21:59:59Z',
                'ShowCampaignInterval' => true,
            ],
        ], $sourceProductId);
    }

    private function hiddenOffer(Paper $paper): ScrapedOffer
    {
        return $this->offer($paper, 'Hidden Nemlig Product', [
            'Category' => 'Grønt',
            'SubCategory' => 'Agurk / Tomat',
            'Campaign' => [
                'CampaignPrice' => 20,
                'IntervalStart' => '2026-05-31T22:00:00Z',
                'IntervalEnd' => '2026-06-30T21:59:59Z',
                'ShowCampaignInterval' => false,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $sourcePayload
     */
    private function offer(Paper $paper, string $title, array $sourcePayload, string $sourceProductId = 'source-product'): ScrapedOffer
    {
        $offer = ScrapedOffer::factory()->for($paper->grocer)->for($paper->importBatch)->for($paper)->create([
            'title' => $title,
            'source_product_id' => $sourceProductId,
            'source_offer_id' => $sourceProductId,
            'price' => 60,
            'source_payload' => $sourcePayload,
        ]);

        OfferSearchDocument::create([
            'scraped_offer_id' => $offer->id,
            'grocer_id' => $paper->grocer_id,
            'paper_id' => $paper->id,
            'grocer_slug' => 'nemlig',
            'grocer_name' => 'Nemlig',
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
            'grocer_id' => $paper->grocer_id,
            'price' => $offer->price,
            'currency' => 'DKK',
            'observed_at' => now(),
            'valid_from' => $paper->active_from,
            'valid_until' => $paper->active_until,
        ]);

        return $offer;
    }

    private function nonNemligPaper(): Paper
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $batch = ImportBatch::factory()->for($grocer)->create([
            'status' => ImportBatchStatus::Succeeded,
            'metadata' => ['source_strategy' => 'nemlig_product_groups'],
        ]);

        return Paper::factory()->for($grocer)->for($batch)->create([
            'source_external_id' => 'nemlig-legacy',
        ]);
    }
}
