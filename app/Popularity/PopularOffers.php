<?php

namespace App\Popularity;

use App\Models\ScrapedOffer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PopularOffers
{
    /**
     * @return Collection<int, ScrapedOffer>
     */
    public function homepageOffers(): Collection
    {
        $offerIds = Cache::remember(OfferPopularity::HOMEPAGE_CACHE_KEY, now()->addMinutes(5), function (): array {
            $ids = $this->rankedOfferIds(
                OfferPopularity::WINDOW_24_HOURS,
                OfferPopularity::sinceFor(OfferPopularity::WINDOW_24_HOURS),
            );

            if ($ids !== []) {
                return $ids;
            }

            $ids = $this->rankedOfferIds(
                OfferPopularity::WINDOW_7_DAYS,
                OfferPopularity::sinceFor(OfferPopularity::WINDOW_7_DAYS),
            );

            if ($ids !== []) {
                return $ids;
            }

            return $this->fallbackOfferIds();
        });

        if ($offerIds === []) {
            return collect();
        }

        $offers = $this->activeOffersQuery()
            ->whereKey($offerIds)
            ->get()
            ->keyBy('id');

        return collect($offerIds)
            ->map(fn (string $offerId): ?ScrapedOffer => $offers->get($offerId))
            ->filter()
            ->values();
    }

    /**
     * @return list<string>
     */
    private function rankedOfferIds(string $window, Carbon $since): array
    {
        return ScrapedOffer::query()
            ->publiclyActive()
            ->join('offer_popularity_scores', 'scraped_offers.id', '=', 'offer_popularity_scores.scraped_offer_id')
            ->where('offer_popularity_scores.window', $window)
            ->where('offer_popularity_scores.score', '>', 0)
            ->where('offer_popularity_scores.last_event_at', '>=', $since)
            ->orderByDesc('offer_popularity_scores.score')
            ->orderByDesc('offer_popularity_scores.last_event_at')
            ->orderBy('offer_popularity_scores.scraped_offer_id')
            ->limit(OfferPopularity::HOMEPAGE_LIMIT)
            ->pluck('offer_popularity_scores.scraped_offer_id')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function fallbackOfferIds(): array
    {
        return $this->activeOffersQuery()
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->limit(OfferPopularity::HOMEPAGE_LIMIT)
            ->pluck('id')
            ->values()
            ->all();
    }

    /**
     * @return Builder<ScrapedOffer>
     */
    private function activeOffersQuery(): Builder
    {
        return ScrapedOffer::query()
            ->forHomepageCards();
    }
}
