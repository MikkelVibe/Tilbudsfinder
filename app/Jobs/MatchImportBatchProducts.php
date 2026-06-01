<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\ProductMatching\ProductMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Support\Facades\Log;

#[DeleteWhenMissingModels]
class MatchImportBatchProducts implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        #[WithoutRelations]
        public ImportBatch $importBatch,
    ) {}

    public function handle(ProductMatcher $matcher): void
    {
        $result = $matcher->matchImportBatch($this->importBatch);

        Log::info('Product matching completed for import batch.', [
            'import_batch_id' => $this->importBatch->id,
            ...$result,
        ]);
    }
}
