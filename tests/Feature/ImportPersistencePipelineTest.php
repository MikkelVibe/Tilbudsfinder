<?php

namespace Tests\Feature;

use App\Enums\GrocerHealthStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationStatus;
use App\Enums\ScrapeJobStatus;
use App\Imports\DTO\ParsedPaperInput;
use App\Imports\Exceptions\DuplicatePaperImportException;
use App\Imports\Exceptions\ImportPipelineException;
use App\Imports\ImportPersistencePipeline;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\NormalizationFailure;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Models\ScrapeJob;
use App\Normalization\DTO\ParsedOfferInput;
use App\Normalization\Enums\NormalizationIssueCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportPersistencePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_import_batch_paper_offers_failures_and_raw_payload_metadata(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $job = ScrapeJob::factory()->for($grocer)->create(['status' => ScrapeJobStatus::Running]);
        $paperInput = $this->paperInput([
            ...$this->validOffers(10),
            new ParsedOfferInput(title: 'Mystery product', price: '20', packageText: 'ukendt størrelse'),
            new ParsedOfferInput(title: 'App-only offer', price: '10', isConditional: true, sourcePayload: ['id' => 'conditional']),
        ], rawPayload: '{"paper":"fixture"}');

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paperInput, $job);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(12, $batch->parsed_offer_count);
        $this->assertSame(11, $batch->published_offer_count);
        $this->assertSame(2, $batch->normalization_failure_count);
        $this->assertNotNull($batch->raw_payload_path);
        $this->assertSame(hash('sha256', '{"paper":"fixture"}'), $batch->raw_payload_sha256);
        $this->assertSame(strlen('{"paper":"fixture"}'), $batch->raw_payload_size_bytes);
        Storage::disk('local')->assertExists($batch->raw_payload_path);

        $this->assertSame(1, Paper::query()->where('import_batch_id', $batch->id)->count());
        $this->assertSame(11, ScrapedOffer::query()->where('import_batch_id', $batch->id)->count());
        $this->assertSame(2, NormalizationFailure::query()->where('import_batch_id', $batch->id)->count());
        $this->assertSame(1, NormalizationFailure::query()->whereNull('scraped_offer_id')->count());

        $partialOffer = ScrapedOffer::query()->where('title', 'Mystery product')->firstOrFail();
        $this->assertSame(NormalizationStatus::Partial, $partialOffer->normalization_status);
        $this->assertNull($partialOffer->unit_price);

        $this->assertSame(ScrapeJobStatus::Succeeded, $job->refresh()->status);
        $this->assertSame(GrocerHealthStatus::Healthy, $grocer->refresh()->health_status);
        $this->assertNotNull($grocer->last_success_at);
    }

    public function test_it_rejects_duplicate_paper_external_ids_before_creating_a_batch(): void
    {
        $grocer = Grocer::factory()->create();
        $paperInput = $this->paperInput($this->validOffers(10), sourceExternalId: 'paper-duplicate');
        $pipeline = new ImportPersistencePipeline;

        $pipeline->persist($grocer, $paperInput);

        try {
            $pipeline->persist($grocer, $paperInput);
            $this->fail('Expected duplicate paper exception.');
        } catch (DuplicatePaperImportException) {
            $this->assertSame(1, ImportBatch::query()->count());
        }
    }

    public function test_it_fails_before_batch_when_parsed_offer_count_is_below_minimum(): void
    {
        $grocer = Grocer::factory()->create();
        $job = ScrapeJob::factory()->for($grocer)->create(['status' => ScrapeJobStatus::Running]);

        try {
            (new ImportPersistencePipeline)->persist($grocer, $this->paperInput($this->validOffers(9)), $job);
            $this->fail('Expected pipeline exception.');
        } catch (ImportPipelineException) {
            $this->assertSame(0, ImportBatch::query()->count());
            $this->assertSame(ScrapeJobStatus::Failed, $job->refresh()->status);
            $this->assertSame(GrocerHealthStatus::Failing, $grocer->refresh()->health_status);
        }
    }

    public function test_it_fails_batch_when_zero_offers_are_publishable(): void
    {
        $grocer = Grocer::factory()->create();
        $job = ScrapeJob::factory()->for($grocer)->create(['status' => ScrapeJobStatus::Running]);
        $offers = array_fill(0, 10, new ParsedOfferInput(title: 'App-only offer', price: '10', isConditional: true));

        try {
            (new ImportPersistencePipeline)->persist($grocer, $this->paperInput($offers), $job);
            $this->fail('Expected pipeline exception.');
        } catch (ImportPipelineException) {
            $batch = ImportBatch::query()->firstOrFail();

            $this->assertSame(ImportBatchStatus::Failed, $batch->status);
            $this->assertSame(0, $batch->published_offer_count);
            $this->assertSame(10, $batch->normalization_failure_count);
            $this->assertSame(10, NormalizationFailure::query()->where('code', NormalizationIssueCode::ConditionalOffer->value)->count());
            $this->assertSame(ScrapeJobStatus::Failed, $job->refresh()->status);
        }
    }

    /**
     * @param  list<ParsedOfferInput>  $offers
     */
    private function paperInput(array $offers, string $sourceExternalId = 'paper-2026-week-22', ?string $rawPayload = null): ParsedPaperInput
    {
        return new ParsedPaperInput(
            sourceExternalId: $sourceExternalId,
            activeFrom: CarbonImmutable::parse('2026-05-28 00:00:00'),
            activeUntil: CarbonImmutable::parse('2026-06-04 23:59:59'),
            offers: $offers,
            title: 'Uge 22',
            sourceUrl: 'https://example.test/paper',
            rawPayload: $rawPayload,
        );
    }

    /**
     * @return list<ParsedOfferInput>
     */
    private function validOffers(int $count): array
    {
        $offers = [];

        for ($i = 1; $i <= $count; $i++) {
            $offers[] = new ParsedOfferInput(
                title: "Offer {$i}",
                price: '10',
                packageText: '500 g',
                sourceOfferId: "offer-{$i}",
                sourceProductId: "product-{$i}",
                sourcePayload: ['id' => $i],
            );
        }

        return $offers;
    }
}
