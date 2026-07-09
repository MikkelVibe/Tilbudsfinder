<?php

namespace App\Popularity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OfferPopularityAggregator
{
    public function aggregate(string $scrapedOfferId): void
    {
        DB::transaction(function () use ($scrapedOfferId): void {
            foreach (OfferPopularity::windows() as $window) {
                $this->aggregateScore($scrapedOfferId, $window, OfferPopularity::sinceFor($window));
            }
        });

        Cache::forget(OfferPopularity::HOMEPAGE_CACHE_KEY);
    }

    private function aggregateScore(string $scrapedOfferId, string $window, Carbon $since): void
    {
        $metrics = $this->metrics($scrapedOfferId, $since);

        DB::table('offer_popularity_scores')->upsert([[
            'scraped_offer_id' => $scrapedOfferId,
            'window' => $window,
            'score' => $metrics['accepted_views'],
            'unique_sessions' => $metrics['unique_sessions'],
            'capped_views' => $metrics['accepted_views'],
            'last_event_at' => $metrics['last_event_at'],
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['scraped_offer_id', 'window'], ['score', 'unique_sessions', 'capped_views', 'last_event_at', 'calculated_at', 'updated_at']);
    }

    /**
     * @return array{unique_sessions: int, accepted_views: int, last_event_at: string|null}
     */
    private function metrics(string $scrapedOfferId, Carbon $since): array
    {
        $metrics = DB::table('offer_popularity_events')
            ->selectRaw('COUNT(*) as accepted_views')
            ->selectRaw('COUNT(DISTINCT session_hash) as unique_sessions')
            ->selectRaw('MAX(occurred_at) as last_event_at')
            ->where('scraped_offer_id', $scrapedOfferId)
            ->where('event_type', OfferPopularity::DETAIL_VIEW_EVENT)
            ->where('is_bot', false)
            ->where('occurred_at', '>=', $since)
            ->first();

        return [
            'unique_sessions' => (int) $metrics->unique_sessions,
            'accepted_views' => (int) $metrics->accepted_views,
            'last_event_at' => $metrics->last_event_at,
        ];
    }
}
