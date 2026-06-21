<?php

namespace App\Console\Commands;

use App\Models\ScrapedOffer;
use App\Search\OfferSearchDocumentBuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('offers:rebuild-search-documents
    {--chunk=500 : Number of scraped offers to rebuild per batch}')]
#[Description('Rebuild database-backed offer search documents from scraped offers')]
class RebuildOfferSearchDocumentsCommand extends Command
{
    public function handle(OfferSearchDocumentBuilder $builder): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $count = 0;

        ScrapedOffer::query()
            ->with(['grocer', 'paper', 'grocerProduct', 'productMatch.canonicalProduct'])
            ->orderBy('id')
            ->chunk($chunkSize, function ($offers) use ($builder, &$count): void {
                foreach ($offers as $offer) {
                    $builder->updateForOffer($offer);
                    $count++;
                }

                $this->line('Rebuilt '.$count.' offer search documents...');
            });

        $this->info('Done. Rebuilt '.$count.' offer search documents.');

        return self::SUCCESS;
    }
}
