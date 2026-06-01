<?php

namespace App\Console\Commands;

use App\Enums\ImportBatchStatus;
use App\Jobs\MatchImportBatchProducts;
use App\Models\ImportBatch;
use App\ProductMatching\ProductMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('products:match-pending {--sync : Run matching immediately instead of dispatching jobs} {--limit= : Maximum number of import batches to process} {--queue=matching : Queue name for dispatched matching jobs}')]
#[Description('Match successful import batches that still have unmatched scraped offers')]
class MatchPendingProductsCommand extends Command
{
    public function handle(ProductMatcher $matcher): int
    {
        $limit = $this->option('limit') === null ? null : max(1, (int) $this->option('limit'));
        $queue = (string) $this->option('queue');
        $sync = (bool) $this->option('sync');
        $processed = 0;

        $query = ImportBatch::query()
            ->where('status', ImportBatchStatus::Succeeded)
            ->whereHas('scrapedOffers', fn ($query) => $query->whereDoesntHave('productMatch'))
            ->oldest();

        if ($limit !== null) {
            $query->limit($limit);
        }

        $batches = $query->get();

        foreach ($batches as $batch) {
            if ($sync) {
                $result = $matcher->matchImportBatch($batch);

                $this->line("Matched batch {$batch->id}: matched={$result['matched']} skipped={$result['skipped']} conflicts={$result['conflicts']}");
            } else {
                MatchImportBatchProducts::dispatch($batch)->onQueue($queue);

                $this->line("Dispatched matching job for batch {$batch->id}.");
            }

            $processed++;
        }

        $this->info(($sync ? 'Processed' : 'Dispatched').' import batches: '.$processed);

        return self::SUCCESS;
    }
}
