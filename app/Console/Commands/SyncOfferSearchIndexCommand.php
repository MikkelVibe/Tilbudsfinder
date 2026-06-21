<?php

namespace App\Console\Commands;

use App\Models\OfferSearchDocument;
use App\Search\MeilisearchOfferSearchEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('offers:sync-search-index
    {--settings : Sync Meilisearch index settings before indexing}
    {--flush : Delete all existing documents from the index before indexing}
    {--chunk=500 : Number of search documents to send per batch}')]
#[Description('Sync offer search documents into Meilisearch')]
class SyncOfferSearchIndexCommand extends Command
{
    public function handle(MeilisearchOfferSearchEngine $engine): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($this->option('settings')) {
            $engine->syncSettings();
            $this->info('Meilisearch offer index settings synced.');
        }

        if ($this->option('flush')) {
            $engine->deleteAllDocuments();
            $this->info('Meilisearch offer index flushed.');
        }

        $count = 0;

        OfferSearchDocument::query()
            ->orderBy('id')
            ->chunk($chunkSize, function ($documents) use ($engine, &$count): void {
                $engine->indexDocuments($documents);
                $count += $documents->count();
                $this->line('Synced '.$count.' offer search documents...');
            });

        $this->info('Done. Synced '.$count.' offer search documents.');

        return self::SUCCESS;
    }
}
