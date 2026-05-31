<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\ProductMatching\ProductMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('products:match {import_batch : Import batch UUID to match}')]
#[Description('Match scraped offers from an import batch to canonical products')]
class MatchProductsCommand extends Command
{
    public function handle(ProductMatcher $matcher): int
    {
        $batch = ImportBatch::query()->find((string) $this->argument('import_batch'));

        if ($batch === null) {
            $this->error('Import batch ['.$this->argument('import_batch').'] does not exist.');

            return self::FAILURE;
        }

        $result = $matcher->matchImportBatch($batch);

        $this->info('Product matching completed.');
        $this->line("Matched: {$result['matched']}");
        $this->line("Ambiguous: {$result['ambiguous']}");
        $this->line("Skipped: {$result['skipped']}");
        $this->line("Conflicts: {$result['conflicts']}");

        return self::SUCCESS;
    }
}
