<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\ProductMatching\ProductMatcher;
use App\Search\OfferSearchDocumentBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Log;
use Throwable;

#[DeleteWhenMissingModels]
class MatchImportBatchProducts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [5, 30, 90];

    public function __construct(
        #[WithoutRelations]
        public ImportBatch $importBatch,
    ) {}

    public function handle(ProductMatcher $matcher, OfferSearchDocumentBuilder $searchDocumentBuilder): void
    {
        if (! $this->isCurrentBatch()) {
            Log::info('Skipped product matching for a superseded import batch.', [
                'import_batch_id' => $this->importBatch->id,
            ]);

            return;
        }

        $result = $matcher->matchImportBatch($this->importBatch);

        if ($this->isCurrentBatch()) {
            $searchDocumentBuilder->rebuildForImportBatch($this->importBatch);
        }

        Log::info('Product matching completed for import batch.', [
            'import_batch_id' => $this->importBatch->id,
            ...$result,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Product matching failed for import batch.', [
            'import_batch_id' => $this->importBatch->id,
            'exception' => $exception === null ? null : $exception::class,
            'message' => $exception?->getMessage(),
        ]);
    }

    private function isCurrentBatch(): bool
    {
        return $this->importBatch->papers()->exists();
    }
}
