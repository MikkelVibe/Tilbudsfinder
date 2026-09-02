<?php

namespace Tests\Feature;

use App\Enums\GrocerHealthStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationStatus;
use App\Enums\ScrapeJobStatus;
use App\Imports\DTO\ImportIssueInput;
use App\Imports\DTO\ParsedPaperInput;
use App\Imports\Exceptions\DuplicatePaperImportException;
use App\Imports\Exceptions\ImportPipelineException;
use App\Imports\ImportPersistencePipeline;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\NormalizationFailure;
use App\Models\OfferSearchDocument;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Models\ScrapeJob;
use App\Normalization\DTO\ParsedOfferInput;
use App\Normalization\Enums\NormalizationIssueCode;
use App\Search\OfferSearchDocumentBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
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
        $this->assertSame(11, OfferSearchDocument::query()->where('grocer_slug', 'rema1000')->count());
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

    public function test_it_does_not_fail_successful_import_when_matching_dispatch_fails(): void
    {
        Storage::fake('local');
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('Queue backend unavailable.'));

        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $paperInput = $this->paperInput($this->validOffers(10), sourceExternalId: 'paper-with-queue-failure');

        $batch = (new ImportPersistencePipeline)->persist($grocer, $paperInput);

        $this->assertSame(ImportBatchStatus::Succeeded, $batch->status);
        $this->assertSame(1, Paper::query()->where('source_external_id', 'paper-with-queue-failure')->count());
    }

    public function test_search_document_failure_rolls_back_rema_activation_and_preserves_previous_results(): void
    {
        Storage::fake('local');

        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $pipeline = new ImportPersistencePipeline;
        $firstBatch = $pipeline->persist($grocer, $this->paperInput(
            $this->validOffers(10),
            sourceExternalId: 'paper-with-index-failure',
        ));
        $paper = Paper::query()->where('source_external_id', 'paper-with-index-failure')->firstOrFail();

        $searchDocumentBuilder = Mockery::mock(OfferSearchDocumentBuilder::class);
        $searchDocumentBuilder
            ->shouldReceive('rebuildForImportBatch')
            ->once()
            ->andThrow(new \RuntimeException('Index unavailable.'));

        $job = ScrapeJob::factory()->for($grocer)->create(['status' => ScrapeJobStatus::Running]);
        $paperInput = new ParsedPaperInput(
            sourceExternalId: 'paper-with-index-failure',
            activeFrom: CarbonImmutable::parse('2026-05-28 00:00:00'),
            activeUntil: CarbonImmutable::parse('2026-06-04 23:59:59'),
            offers: $this->validOffers(10),
            metadata: $this->remaMetadata(10, 10, 10),
            reconcileExistingPaper: true,
        );

        try {
            (new ImportPersistencePipeline(searchDocumentBuilder: $searchDocumentBuilder))->persist($grocer, $paperInput, $job);
            $this->fail('Expected index failure to fail the import.');
        } catch (ImportPipelineException $exception) {
            $this->assertSame('Index unavailable.', $exception->getMessage());
        }

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame($firstBatch->id, $paper->refresh()->import_batch_id);
        $this->assertSame(10, OfferSearchDocument::query()->count());
        $this->assertSame(ScrapeJobStatus::Failed, $job->refresh()->status);
        $this->assertSame(GrocerHealthStatus::Failing, $grocer->refresh()->health_status);
        $this->assertSame(1, Paper::query()->where('source_external_id', 'paper-with-index-failure')->count());
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

    public function test_it_reconciles_rema_paper_to_a_new_current_snapshot_and_logs_ignored_rows(): void
    {
        Storage::fake('local');
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-03 12:00:00');
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $pipeline = new ImportPersistencePipeline;
        $metadata = [
            'source_strategy' => 'rema_tjek_offer_match',
            'fetched_offer_count' => 1,
            'matched_tjek_offer_count' => 1,
            'ambiguous_tjek_offer_count' => 0,
            'missing_tjek_offer_count' => 0,
        ];

        $first = new ParsedPaperInput(
            sourceExternalId: 'week-36',
            activeFrom: CarbonImmutable::parse('2026-08-30 00:00:00'),
            activeUntil: CarbonImmutable::parse('2026-09-06 00:00:00'),
            offers: [new ParsedOfferInput(
                title: 'Old product',
                price: 10,
                packageText: '1 kg',
                sourceOfferId: 'tjek-old',
                sourceProductId: 'rema-old',
            )],
            metadata: $metadata,
            reconcileExistingPaper: true,
        );
        $firstBatch = $pipeline->persist($grocer, $first);

        $second = new ParsedPaperInput(
            sourceExternalId: 'week-36',
            activeFrom: CarbonImmutable::parse('2026-08-30 00:00:00'),
            activeUntil: CarbonImmutable::parse('2026-09-06 00:00:00'),
            offers: [
                new ParsedOfferInput(
                    title: 'New Thursday product',
                    price: 12,
                    packageText: '1 kg',
                    sourceOfferId: 'tjek-new',
                    sourceProductId: 'rema-new',
                ),
                new ParsedOfferInput(
                    title: 'Invalid missing-ID product',
                    price: 14,
                    packageText: '1 kg',
                    sourceOfferId: 'tjek-no-id',
                ),
            ],
            issues: [new ImportIssueInput(
                code: 'missing_rema_product',
                message: 'Tjek offer has no REMA product.',
                sourceCatalogId: 'week-36',
                sourceOfferId: 'tjek-plant',
            )],
            metadata: [...$metadata, 'fetched_offer_count' => 2, 'missing_tjek_offer_count' => 1],
            reconcileExistingPaper: true,
        );
        $secondBatch = $pipeline->persist($grocer, $second);

        $paper = Paper::query()->where('source_external_id', 'week-36')->firstOrFail();
        $this->assertSame(1, Paper::query()->count());
        $this->assertSame(2, ImportBatch::query()->count());
        $this->assertSame($secondBatch->id, $paper->import_batch_id);
        $this->assertSame(0, ScrapedOffer::query()->where('import_batch_id', $firstBatch->id)->publiclyActive()->count());
        $this->assertSame(1, ScrapedOffer::query()->where('import_batch_id', $secondBatch->id)->publiclyActive()->count());
        $this->assertSame(1, OfferSearchDocument::query()->count());
        $this->assertSame(2, $secondBatch->metadata['import_issue_count']);
        $this->assertSame(
            ['missing_rema_product', 'missing_rema_product_id'],
            array_column($secondBatch->metadata['import_issues'], 'code'),
        );
        $this->assertSame(0, ScrapedOffer::query()->where('source_offer_id', 'tjek-no-id')->count());
    }

    public function test_low_rema_coverage_replaces_the_current_snapshot_and_logs_unmatched_rows(): void
    {
        Storage::fake('local');
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-03 12:00:00');
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $pipeline = new ImportPersistencePipeline;
        $currentBatch = $pipeline->persist($grocer, new ParsedPaperInput(
            sourceExternalId: 'week-36',
            activeFrom: CarbonImmutable::parse('2026-08-30 00:00:00'),
            activeUntil: CarbonImmutable::parse('2026-09-06 00:00:00'),
            offers: $this->validOffers(10),
            metadata: $this->remaMetadata(10, 10, 10),
            reconcileExistingPaper: true,
        ));
        $paper = Paper::query()->where('source_external_id', 'week-36')->firstOrFail();

        $issues = array_map(
            fn (int $number): ImportIssueInput => new ImportIssueInput(
                code: 'missing_rema_product',
                message: 'Tjek offer has no REMA product.',
                sourceCatalogId: 'week-36',
                sourceOfferId: 'missing-'.$number,
            ),
            range(1, 119),
        );

        $replacementBatch = $pipeline->persist($grocer, new ParsedPaperInput(
            sourceExternalId: 'week-36',
            activeFrom: CarbonImmutable::parse('2026-08-30 00:00:00'),
            activeUntil: CarbonImmutable::parse('2026-09-06 00:00:00'),
            offers: $this->validOffers(1),
            issues: $issues,
            metadata: $this->remaMetadata(120, 1, 1),
            reconcileExistingPaper: true,
        ));

        $this->assertSame(ImportBatchStatus::Succeeded, $replacementBatch->status);
        $this->assertSame(1, $replacementBatch->published_offer_count);
        $this->assertSame(119, $replacementBatch->metadata['import_issue_count']);
        $this->assertSame('missing_rema_product', $replacementBatch->metadata['import_issues'][0]['code']);
        $this->assertSame($replacementBatch->id, $paper->refresh()->import_batch_id);
        $this->assertSame(0, ScrapedOffer::query()->where('import_batch_id', $currentBatch->id)->publiclyActive()->count());
        $this->assertSame(1, ScrapedOffer::query()->where('import_batch_id', $replacementBatch->id)->publiclyActive()->count());
        $this->assertSame(1, OfferSearchDocument::query()->count());
    }

    public function test_zero_rema_coverage_is_logged_without_replacing_the_current_snapshot(): void
    {
        Storage::fake('local');
        Queue::fake();
        CarbonImmutable::setTestNow('2026-09-03 12:00:00');
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $pipeline = new ImportPersistencePipeline;
        $currentBatch = $pipeline->persist($grocer, new ParsedPaperInput(
            sourceExternalId: 'week-36',
            activeFrom: CarbonImmutable::parse('2026-08-30 00:00:00'),
            activeUntil: CarbonImmutable::parse('2026-09-06 00:00:00'),
            offers: $this->validOffers(10),
            metadata: $this->remaMetadata(10, 10, 10),
            reconcileExistingPaper: true,
        ));

        $issues = array_map(
            fn (int $number): ImportIssueInput => new ImportIssueInput(
                code: 'missing_rema_product',
                message: 'Tjek offer has no REMA product.',
                sourceCatalogId: 'week-36',
                sourceOfferId: 'missing-'.$number,
            ),
            range(1, 120),
        );

        try {
            $pipeline->persist($grocer, new ParsedPaperInput(
                sourceExternalId: 'week-36',
                activeFrom: CarbonImmutable::parse('2026-08-30 00:00:00'),
                activeUntil: CarbonImmutable::parse('2026-09-06 00:00:00'),
                offers: [],
                issues: $issues,
                metadata: $this->remaMetadata(120, 0, 0),
                reconcileExistingPaper: true,
            ));
            $this->fail('Expected zero REMA coverage to fail the import.');
        } catch (ImportPipelineException $exception) {
            $this->assertSame('Import produced zero publishable offers.', $exception->getMessage());
        }

        $failedBatch = ImportBatch::query()->where('status', ImportBatchStatus::Failed)->firstOrFail();
        $this->assertSame(0, $failedBatch->published_offer_count);
        $this->assertSame(120, $failedBatch->metadata['import_issue_count']);
        $this->assertSame($currentBatch->id, Paper::query()->where('source_external_id', 'week-36')->value('import_batch_id'));
        $this->assertSame(10, ScrapedOffer::query()->publiclyActive()->count());
        $this->assertSame(10, OfferSearchDocument::query()->count());
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

    /** @return array<string, int|string> */
    private function remaMetadata(int $fetched, int $matchedTjek, int $matchedProducts): array
    {
        return [
            'source_strategy' => 'rema_tjek_offer_match',
            'fetched_offer_count' => $fetched,
            'matched_tjek_offer_count' => $matchedTjek,
            'matched_product_count' => $matchedProducts,
            'ambiguous_tjek_offer_count' => 0,
            'missing_tjek_offer_count' => $fetched - $matchedTjek,
        ];
    }
}
