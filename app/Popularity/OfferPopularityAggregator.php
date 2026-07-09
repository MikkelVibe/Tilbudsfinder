<?php

namespace App\Popularity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OfferPopularityAggregator
{
    public function aggregate(string $scrapedOfferId): void
    {
        $this->aggregateScore($scrapedOfferId, OfferPopularity::WINDOW_24_HOURS, now()->subDay());
        $this->aggregateScore($scrapedOfferId, OfferPopularity::WINDOW_7_DAYS, now()->subDays(7));

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
        $query = DB::table('offer_popularity_events')
            ->where('scraped_offer_id', $scrapedOfferId)
            ->where('event_type', OfferPopularity::DETAIL_VIEW_EVENT)
            ->where('is_bot', false)
            ->where('occurred_at', '>=', $since);

        return [
            'unique_sessions' => (clone $query)->distinct('session_hash')->count('session_hash'),
            'accepted_views' => (clone $query)->count(),
            'last_event_at' => (clone $query)->max('occurred_at'),
        ];
    }
}
