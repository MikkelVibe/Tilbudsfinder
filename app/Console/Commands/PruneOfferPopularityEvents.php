<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('offers:prune-popularity-events')]
#[Description('Prune raw offer popularity events older than 30 days.')]
class PruneOfferPopularityEvents extends Command
{
    public function handle(): int
    {
        $deleted = DB::table('offer_popularity_events')
            ->where('occurred_at', '<', now()->subDays(30))
            ->delete();

        $this->info(sprintf('Pruned %d offer popularity events.', $deleted));

        return self::SUCCESS;
    }
}
