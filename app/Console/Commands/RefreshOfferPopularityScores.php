<?php

namespace App\Console\Commands;

use App\Models\ScrapedOffer;
use App\Popularity\OfferPopularity;
use App\Popularity\OfferPopularityAggregator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Signature('offers:refresh-popularity-scores {--chunk=200}')]
#[Description('Refresh rolling offer popularity scores for active offers with scores or recent events.')]
class RefreshOfferPopularityScores extends Command
{
    public function handle(OfferPopularityAggregator $aggregator): int
    {
        $chunkSize = max(1, (int) $this->option('chunk'));
        $refreshed = 0;
        $oldestRelevantEventAt = now()->subDays(7);

        ScrapedOffer::query()
            ->select(['id'])
            ->publiclyActive()
            ->where(function (Builder $query) use ($oldestRelevantEventAt): void {
                $query
                    ->whereExists(fn ($query) => $query
                        ->selectRaw('1')
                        ->from('offer_popularity_scores')
                        ->whereColumn('offer_popularity_scores.scraped_offer_id', 'scraped_offers.id'))
                    ->orWhereExists(fn ($query) => $query
                        ->selectRaw('1')
                        ->from('offer_popularity_events')
                        ->whereColumn('offer_popularity_events.scraped_offer_id', 'scraped_offers.id')
                        ->where('offer_popularity_events.event_type', OfferPopularity::DETAIL_VIEW_EVENT)
                        ->where('offer_popularity_events.is_bot', false)
                        ->where('offer_popularity_events.occurred_at', '>=', $oldestRelevantEventAt));
            })
            ->chunkById($chunkSize, function (Collection $offers) use ($aggregator, &$refreshed): void {
                $offers->each(function (ScrapedOffer $offer) use ($aggregator, &$refreshed): void {
                    $aggregator->aggregate($offer->id);

                    $refreshed++;
                });
            });

        $this->info(sprintf('Refreshed popularity scores for %d offers.', $refreshed));

        return self::SUCCESS;
    }
}
