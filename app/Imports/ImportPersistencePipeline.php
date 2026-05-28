<?php

namespace App\Imports;

use App\Enums\GrocerHealthStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationStatus;
use App\Enums\ScrapeJobStatus;
use App\Imports\DTO\ParsedPaperInput;
use App\Imports\Exceptions\DuplicatePaperImportException;
use App\Imports\Exceptions\ImportPipelineException;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\NormalizationFailure;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Models\ScrapeJob;
use App\Normalization\DTO\NormalizationIssue;
use App\Normalization\DTO\NormalizedOffer;
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\OfferNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportPersistencePipeline
{
    private const MINIMUM_PARSED_OFFERS = 10;

    private const RAW_PAYLOAD_RETENTION_DAYS = 90;

    public function __construct(
        private readonly OfferNormalizer $offerNormalizer = new OfferNormalizer,
    ) {}

    public function persist(Grocer $grocer, ParsedPaperInput $paperInput, ?ScrapeJob $scrapeJob = null): ImportBatch
    {
        try {
            $this->validateInput($grocer, $paperInput);

            $batch = DB::transaction(function () use ($grocer, $paperInput, $scrapeJob): ImportBatch {
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

                $paper = Paper::create([
                    'grocer_id' => $grocer->id,
                    'import_batch_id' => $batch->id,
                    'source_external_id' => $paperInput->sourceExternalId,
                    'title' => $paperInput->title,
                    'active_from' => $paperInput->activeFrom,
                    'active_until' => $paperInput->activeUntil,
                ]);

                $publishedCount = 0;
                $failureCount = 0;

                foreach ($paperInput->offers as $offerInput) {
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
                    ]);

                    return $batch->refresh();
                }

                $batch->update([
                    'status' => ImportBatchStatus::Succeeded,
                    'published_offer_count' => $publishedCount,
                    'normalization_failure_count' => $failureCount,
                    'finished_at' => now(),
                ]);

                $grocer->update([
                    'health_status' => GrocerHealthStatus::Healthy,
                    'last_success_at' => now(),
                ]);

                $scrapeJob?->update([
                    'status' => ScrapeJobStatus::Succeeded,
                    'finished_at' => now(),
                    'failure_reason' => null,
                ]);

                return $batch->refresh();
            });

            if ($batch->status === ImportBatchStatus::Failed) {
                throw new ImportPipelineException($batch->failure_reason ?? 'Import failed.');
            }

            return $batch;
        } catch (DuplicatePaperImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $scrapeJob?->update([
                'status' => ScrapeJobStatus::Failed,
                'finished_at' => now(),
                'failure_reason' => $exception->getMessage(),
            ]);

            $grocer->update([
                'health_status' => GrocerHealthStatus::Failing,
                'last_failure_at' => now(),
            ]);

            if ($exception instanceof ImportPipelineException) {
                throw $exception;
            }

            throw new ImportPipelineException($exception->getMessage(), previous: $exception);
        }
    }

    private function validateInput(Grocer $grocer, ParsedPaperInput $paperInput): void
    {
        if (count($paperInput->offers) < self::MINIMUM_PARSED_OFFERS) {
            throw new ImportPipelineException('Parsed paper must contain at least '.self::MINIMUM_PARSED_OFFERS.' offers.');
        }

        if (Paper::query()->where('grocer_id', $grocer->id)->where('source_external_id', $paperInput->sourceExternalId)->exists()) {
            throw new DuplicatePaperImportException('Paper has already been imported for this grocer.');
        }
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
        return ScrapedOffer::create([
            'grocer_id' => $grocer->id,
            'import_batch_id' => $batch->id,
            'paper_id' => $paper->id,
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
