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
    private const CHUNK_SIZE = 1000;

    public function handle(): int
    {
        $deleted = 0;
        $cutoff = now()->subDays(30);

        while (true) {
            $ids = DB::table('offer_popularity_events')
                ->where('occurred_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::CHUNK_SIZE)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table('offer_popularity_events')
                ->whereIn('id', $ids)
                ->delete();
        }

        $this->info(sprintf('Pruned %d offer popularity events.', $deleted));

        return self::SUCCESS;
    }
}
