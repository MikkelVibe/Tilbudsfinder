<?php

namespace App\Imports;

use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationStatus;
use App\Imports\DTO\ParsedPaperInput;
use App\Imports\Exceptions\DuplicatePaperImportException;
use App\Imports\Exceptions\ImportPipelineException;
use App\Jobs\MatchImportBatchProducts;
use App\Models\Grocer;
use App\Models\GrocerProduct;
use App\Models\ImportBatch;
use App\Models\NormalizationFailure;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Models\ScrapeJob;
use App\Normalization\DTO\NormalizationIssue;
use App\Normalization\DTO\NormalizedOffer;
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\OfferNormalizer;
use App\Scrapers\Exceptions\StaleScrapeJobAttemptException;
use App\Search\OfferSearchDocumentBuilder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportPersistencePipeline
{
    private const MINIMUM_PARSED_OFFERS = 10;

    private const RAW_PAYLOAD_RETENTION_DAYS = 90;

    public function __construct(
        private readonly OfferNormalizer $offerNormalizer = new OfferNormalizer,
        private readonly OfferSearchDocumentBuilder $searchDocumentBuilder = new OfferSearchDocumentBuilder,
    ) {}

    public function persist(Grocer $grocer, ParsedPaperInput $paperInput, ?ScrapeJob $scrapeJob = null): ImportBatch
    {
        try {
            $this->validateInput($grocer, $paperInput);

            $batch = DB::transaction(function () use ($grocer, $paperInput, $scrapeJob): ImportBatch {
                $this->lockActiveScrapeJobAttemptOrFail($scrapeJob);

                $batch = ImportBatch::create([
                    'grocer_id' => $grocer->id,
                    'scrape_job_id' => $scrapeJob?->id,
                    'status' => ImportBatchStatus::Persisting,
                    'source_type' => 'json',
                    'source_url' => $paperInput->sourceUrl,
                    'source_external_id' => $paperInput->sourceExternalId,
                    'parsed_offer_count' => count($paperInput->offers),
                    'started_at' => now(),
                    'metadata' => $paperInput->metadata ?: null,
                ]);

                $this->storeRawPayload($batch, $grocer, $paperInput);

                $importIssues = $this->serializedImportIssues($paperInput);

                $paper = $this->lockOrCreatePaper($grocer, $batch, $paperInput);

                $publishedCount = 0;
                $failureCount = 0;

                foreach ($paperInput->offers as $offerInput) {
                    if ($paperInput->reconcileExistingPaper && blank($offerInput->sourceProductId)) {
                        $importIssues[] = [
                            'code' => 'missing_rema_product_id',
                            'source_catalog_id' => $paperInput->sourceExternalId,
                            'source_offer_id' => $offerInput->sourceOfferId,
                            'message' => 'Matched REMA offer was withheld because it has no REMA product ID.',
                            'context' => ['title' => $offerInput->title],
                        ];

                        continue;
                    }

                    $normalizedOffer = $this->offerNormalizer->normalize($offerInput);

                    if ($normalizedOffer->status === NormalizedOfferStatus::Rejected) {
                        $failureCount += $this->persistIssues($grocer, $batch, null, $normalizedOffer);

                        continue;
                    }

                    $scrapedOffer = $this->persistOffer($grocer, $batch, $paper, $normalizedOffer);
                    $publishedCount++;
                    $failureCount += $this->persistIssues($grocer, $batch, $scrapedOffer, $normalizedOffer);
                }

                if ($publishedCount === 0) {
                    $batch->update([
                        'status' => ImportBatchStatus::Failed,
                        'published_offer_count' => 0,
                        'normalization_failure_count' => $failureCount,
                        'finished_at' => now(),
                        'failure_reason' => 'Import produced zero publishable offers.',
                        'metadata' => [
                            ...($batch->metadata ?? []),
                            'import_issue_count' => count($importIssues),
                            'import_issues' => $importIssues,
                        ],
                    ]);

                    return $batch->refresh();
                }

                $batch->update([
                    'status' => ImportBatchStatus::Succeeded,
                    'published_offer_count' => $publishedCount,
                    'normalization_failure_count' => $failureCount,
                    'finished_at' => now(),
                    'metadata' => [
                        ...($batch->metadata ?? []),
                        'import_issue_count' => count($importIssues),
                        'import_issues' => $importIssues,
                    ],
                ]);

                $paper->update([
                    'import_batch_id' => $batch->id,
                    'title' => $paperInput->title,
                    'active_from' => $paperInput->activeFrom,
                    'active_until' => $paperInput->activeUntil,
                ]);

                $this->searchDocumentBuilder->rebuildForImportBatch($batch);

                return $batch->refresh();
            });

            if ($batch->status === ImportBatchStatus::Failed) {
                throw new ImportPipelineException($batch->failure_reason ?? 'Import failed.');
            }

            $this->dispatchProductMatching($batch);

            return $batch;
        } catch (DuplicatePaperImportException|StaleScrapeJobAttemptException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof ImportPipelineException) {
                throw $exception;
            }

            throw new ImportPipelineException($exception->getMessage(), previous: $exception);
        }
    }

    private function lockActiveScrapeJobAttemptOrFail(?ScrapeJob $expectedJob): void
    {
        if ($expectedJob === null) {
            return;
        }

        $currentJob = ScrapeJob::query()
            ->whereKey($expectedJob->id)
            ->lockForUpdate()
            ->first();

        if ($currentJob === null
            || $expectedJob->scraper_agent_id === null
            || ! $currentJob->isActiveAttempt(
                $expectedJob->scraper_agent_id,
                $expectedJob->attempt,
                $expectedJob->status,
            )) {
            throw new StaleScrapeJobAttemptException;
        }
    }

    private function dispatchProductMatching(ImportBatch $batch): void
    {
        try {
            MatchImportBatchProducts::dispatch($batch)->afterCommit()->onQueue('matching');
        } catch (Throwable $exception) {
            Log::warning('Product matching dispatch failed after successful import.', [
                'import_batch_id' => $batch->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function validateInput(Grocer $grocer, ParsedPaperInput $paperInput): void
    {
        if (! $this->allowsSmallParsedOfferCount($paperInput) && count($paperInput->offers) < self::MINIMUM_PARSED_OFFERS) {
            throw new ImportPipelineException('Parsed paper must contain at least '.self::MINIMUM_PARSED_OFFERS.' offers.');
        }

        if (! $paperInput->reconcileExistingPaper && Paper::query()->where('grocer_id', $grocer->id)->where('source_external_id', $paperInput->sourceExternalId)->exists()) {
            throw new DuplicatePaperImportException('Paper has already been imported for this grocer.');
        }
    }

    private function allowsSmallParsedOfferCount(ParsedPaperInput $paperInput): bool
    {
        return (($paperInput->metadata['source_strategy'] ?? null) === 'nemlig_product_groups'
            && isset($paperInput->metadata['interval_key']))
            || $paperInput->reconcileExistingPaper;
    }

    private function lockOrCreatePaper(Grocer $grocer, ImportBatch $batch, ParsedPaperInput $paperInput): Paper
    {
        $paper = Paper::query()
            ->where('grocer_id', $grocer->id)
            ->where('source_external_id', $paperInput->sourceExternalId)
            ->lockForUpdate()
            ->first();

        if ($paper !== null) {
            return $paper;
        }

        return Paper::create([
            'grocer_id' => $grocer->id,
            'import_batch_id' => $batch->id,
            'source_external_id' => $paperInput->sourceExternalId,
            'title' => $paperInput->title,
            'active_from' => $paperInput->activeFrom,
            'active_until' => $paperInput->activeUntil,
        ]);
    }

    /** @return list<array{code: string, source_catalog_id: ?string, source_offer_id: ?string, message: string, context: array<string, mixed>|null}> */
    private function serializedImportIssues(ParsedPaperInput $paperInput): array
    {
        return array_map(
            fn ($issue): array => [
                'code' => $issue->code,
                'source_catalog_id' => $issue->sourceCatalogId ?? $paperInput->sourceExternalId,
                'source_offer_id' => $issue->sourceOfferId,
                'message' => $issue->message,
                'context' => $issue->context ?: null,
            ],
            $paperInput->issues,
        );
    }

    private function storeRawPayload(ImportBatch $batch, Grocer $grocer, ParsedPaperInput $paperInput): void
    {
        if ($paperInput->rawPayload === null) {
            return;
        }

        $path = sprintf('imports/raw/%s/%s/%s/%s.json', $grocer->slug, now()->format('Y'), now()->format('m'), $batch->id);

        Storage::disk('local')->put($path, $paperInput->rawPayload);

        $batch->update([
            'raw_payload_path' => $path,
            'raw_payload_sha256' => hash('sha256', $paperInput->rawPayload),
            'raw_payload_size_bytes' => strlen($paperInput->rawPayload),
            'raw_payload_retained_until' => now()->addDays(self::RAW_PAYLOAD_RETENTION_DAYS),
        ]);
    }

    private function persistOffer(Grocer $grocer, ImportBatch $batch, Paper $paper, NormalizedOffer $offer): ScrapedOffer
    {
        $grocerProduct = $this->persistGrocerProduct($grocer, $offer);

        return ScrapedOffer::create([
            'grocer_id' => $grocer->id,
            'import_batch_id' => $batch->id,
            'paper_id' => $paper->id,
            'grocer_product_id' => $grocerProduct?->id,
            'source_offer_id' => $offer->sourceOfferId,
            'source_product_id' => $offer->sourceProductId,
            'title' => $offer->title,
            'description' => $offer->description,
            'image_url' => $offer->imageUrl,
            'price' => $offer->price?->decimal(),
            'currency' => $offer->price?->currency ?? 'DKK',
            'package_amount' => $offer->packageAmount ? (string) $offer->packageAmount : null,
            'package_unit_original' => $offer->packageUnitOriginal,
            'package_unit' => $offer->packageUnit?->value,
            'compare_unit' => $offer->compareUnit?->value,
            'unit_price' => $offer->unitPrice?->decimal(),
            'normalization_status' => match ($offer->status) {
                NormalizedOfferStatus::Succeeded => NormalizationStatus::Succeeded,
                NormalizedOfferStatus::Partial => NormalizationStatus::Partial,
                NormalizedOfferStatus::Rejected => NormalizationStatus::Failed,
            },
            'normalization_confidence' => $offer->confidence,
            'source_payload' => $offer->sourcePayload,
        ]);
    }

    private function persistGrocerProduct(Grocer $grocer, NormalizedOffer $offer): ?GrocerProduct
    {
        if ($offer->sourceProductId === null || trim($offer->sourceProductId) === '') {
            return null;
        }

        return GrocerProduct::query()->updateOrCreate(
            [
                'grocer_id' => $grocer->id,
                'source_product_id' => $offer->sourceProductId,
            ],
            array_filter([
                'name' => $offer->title,
                'brand' => $this->metadataString($offer, 'brand'),
                'category' => $this->metadataString($offer, 'category'),
                'subcategory' => $this->metadataString($offer, 'subcategory'),
                'description' => $offer->description,
                'image_url' => $offer->imageUrl,
                'package_amount' => $offer->packageAmount ? (string) $offer->packageAmount : null,
                'package_unit' => $offer->packageUnit?->value,
                'compare_unit' => $offer->compareUnit?->value,
                'declaration' => $this->metadataString($offer, 'declaration'),
                'attributes' => $this->metadataArray($offer, 'attributes'),
                'traceability' => $this->metadataArray($offer, 'traceability'),
                'raw_detail_payload' => $this->metadataArray($offer, 'raw_detail_payload'),
                'nutrition_basis_unit' => $this->metadataString($offer, 'nutrition_basis_unit'),
                'energy_kj_per_100' => $this->nutritionDecimal($offer, 'energy_kj'),
                'energy_kcal_per_100' => $this->nutritionDecimal($offer, 'energy_kcal'),
                'fat_g_per_100' => $this->nutritionDecimal($offer, 'fat'),
                'saturated_fat_g_per_100' => $this->nutritionDecimal($offer, 'saturated_fat'),
                'carbohydrate_g_per_100' => $this->nutritionDecimal($offer, 'carbohydrate'),
                'sugars_g_per_100' => $this->nutritionDecimal($offer, 'sugar'),
                'fiber_g_per_100' => $this->nutritionDecimal($offer, 'dietary_fiber'),
                'protein_g_per_100' => $this->nutritionDecimal($offer, 'protein'),
                'salt_g_per_100' => $this->nutritionDecimal($offer, 'salt'),
                'detail_observed_at' => $this->metadataArray($offer, 'raw_detail_payload') === null ? null : now(),
            ], static fn (mixed $value): bool => $value !== null)
        );
    }

    private function metadataString(NormalizedOffer $offer, string $key): ?string
    {
        $value = $offer->metadata[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function metadataArray(NormalizedOffer $offer, string $key): ?array
    {
        $value = $offer->metadata[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    private function nutritionDecimal(NormalizedOffer $offer, string $key): ?string
    {
        $value = $offer->metadata['nutrition'][$key] ?? null;

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $normalized = str_replace(',', '.', (string) $value);

        if (! is_numeric($normalized)) {
            return null;
        }

        return BigDecimal::of($normalized)->toScale(2, RoundingMode::HALF_UP)->__toString();
    }

    private function persistIssues(Grocer $grocer, ImportBatch $batch, ?ScrapedOffer $offer, NormalizedOffer $normalizedOffer): int
    {
        foreach ($normalizedOffer->issues as $issue) {
            $this->persistIssue($grocer, $batch, $offer, $issue, $normalizedOffer);
        }

        return count($normalizedOffer->issues);
    }

    private function persistIssue(Grocer $grocer, ImportBatch $batch, ?ScrapedOffer $offer, NormalizationIssue $issue, NormalizedOffer $normalizedOffer): void
    {
        NormalizationFailure::create([
            'grocer_id' => $grocer->id,
            'import_batch_id' => $batch->id,
            'scraped_offer_id' => $offer?->id,
            'severity' => $issue->severity,
            'field' => $issue->field,
            'code' => $issue->code->value,
            'message' => $issue->message,
            'context' => array_filter([
                ...$issue->context,
                'title' => $normalizedOffer->title,
                'source_payload' => $normalizedOffer->sourcePayload,
            ], static fn ($value): bool => $value !== null),
        ]);
    }
}
