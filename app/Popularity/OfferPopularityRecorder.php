<?php

namespace App\Popularity;

use App\Jobs\AggregateOfferPopularity;
use App\Models\ScrapedOffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfferPopularityRecorder
{
    public function recordDetailView(ScrapedOffer $offer, Request $request): void
    {
        if (! $this->isEligible($offer) || $this->shouldIgnore($request)) {
            return;
        }

        $sessionHash = $this->sessionHash($request);
        $recorded = Cache::lock($this->recordingLockKey($offer, $sessionHash), 5)
            ->get(fn (): bool => $this->recordAcceptedView($offer, $request, $sessionHash));

        if (! $recorded) {
            return;
        }

        AggregateOfferPopularity::dispatch($offer->id);
    }

    private function recordAcceptedView(ScrapedOffer $offer, Request $request, string $sessionHash): bool
    {
        if ($this->hasReachedDailySessionViewCap($offer, $sessionHash)) {
            return false;
        }

        DB::table('offer_popularity_events')->insert([
            'scraped_offer_id' => $offer->id,
            'session_hash' => $sessionHash,
            'event_type' => OfferPopularity::DETAIL_VIEW_EVENT,
            'occurred_at' => now(),
            'user_agent_family' => $this->userAgentFamily($request),
            'is_bot' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }

    private function isEligible(ScrapedOffer $offer): bool
    {
        return $offer->newQuery()
            ->whereKey($offer->id)
            ->publiclyActive()
            ->exists();
    }

    private function shouldIgnore(Request $request): bool
    {
        return $this->isBot($request);
    }

    private function isBot(Request $request): bool
    {
        $userAgent = Str::lower((string) $request->userAgent());

        return $userAgent === '' || Str::contains($userAgent, [
            'bot',
            'crawler',
            'spider',
            'slurp',
            'preview',
            'facebookexternalhit',
            'whatsapp',
        ]);
    }

    private function sessionHash(Request $request): string
    {
        return hash_hmac('sha256', $request->session()->getId(), (string) config('app.key'));
    }

    private function hasReachedDailySessionViewCap(ScrapedOffer $offer, string $sessionHash): bool
    {
        return DB::table('offer_popularity_events')
            ->where('scraped_offer_id', $offer->id)
            ->where('session_hash', $sessionHash)
            ->where('event_type', OfferPopularity::DETAIL_VIEW_EVENT)
            ->where('occurred_at', '>=', now()->startOfDay())
            ->count() >= OfferPopularity::DAILY_SESSION_VIEW_CAP;
    }

    private function recordingLockKey(ScrapedOffer $offer, string $sessionHash): string
    {
        return sprintf('offer-popularity:%s:%s:%s', $offer->id, $sessionHash, now()->toDateString());
    }

    private function userAgentFamily(Request $request): ?string
    {
        $userAgent = Str::lower((string) $request->userAgent());

        if ($userAgent === '') {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'edg/') => 'edge',
            str_contains($userAgent, 'chrome/') || str_contains($userAgent, 'chromium/') => 'chrome',
            str_contains($userAgent, 'safari/') => 'safari',
            str_contains($userAgent, 'firefox/') => 'firefox',
            default => 'other',
        };
    }
}
