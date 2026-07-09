<?php

namespace App\Http\Controllers;

use App\Models\Grocer;
use App\Models\ScrapedOffer;
use App\Popularity\PopularOffers;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(PopularOffers $popularOffers): Response
    {
        $enabledStoreSlugs = $this->enabledStoreSlugs();

        return Inertia::render('Home', [
            'appName' => config('app.name'),
            'popularOffers' => $this->popularOffers($popularOffers),
            'latestOffers' => $this->latestOffers(),
            'stores' => $this->stores(),
            'allStoreSlugs' => $enabledStoreSlugs,
            'enabledStoreCount' => count($enabledStoreSlugs),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function popularOffers(PopularOffers $popularOffers): array
    {
        return $popularOffers
            ->homepageOffers()
            ->map(fn (ScrapedOffer $offer): array => $this->offerCard($offer))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function latestOffers(): array
    {
        return $this->activeOffersQuery()
            ->latest()
            ->limit(3)
            ->get()
            ->map(fn (ScrapedOffer $offer): array => [
                'id' => $offer->id,
                'title' => $offer->title,
                'price' => $this->formatDecimal($offer->price),
                'meta' => collect([$this->formatOfferAmount($offer) ?: 'UKENDT MÆNGDE', $this->formatUnitPrice($offer) ?: 'UKENDT/STK'])->join(' · '),
                'imageUrl' => $this->imageUrl($offer),
                'fallbackLabel' => mb_substr($offer->title, 0, 8),
                'color' => 'bg-[#f5f3ee]',
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function stores(): array
    {
        return Grocer::query()
            ->select(['id', 'slug', 'name'])
            ->where('is_enabled', true)
            ->withCount(['scrapedOffers as offer_count' => fn ($query) => $query
                ->publiclyActive()])
            ->orderByDesc('offer_count')
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Grocer $grocer): array => [
                'slug' => $grocer->slug,
                'name' => $grocer->name,
                'count' => sprintf('%d tilbud', $grocer->offer_count),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function enabledStoreSlugs(): array
    {
        return Grocer::query()
            ->where('is_enabled', true)
            ->orderBy('name')
            ->pluck('slug')
            ->values()
            ->all();
    }

    /**
     * @return Builder<ScrapedOffer>
     */
    private function activeOffersQuery(): Builder
    {
        return ScrapedOffer::query()
            ->select(['id', 'grocer_id', 'paper_id', 'grocer_product_id', 'title', 'image_url', 'price', 'package_amount', 'package_unit_original', 'package_unit', 'compare_unit', 'unit_price', 'created_at'])
            ->with([
                'grocerProduct:id,image_url',
                'paper:id,active_from,active_until',
                'productMatch.canonicalProduct:id,image_url',
            ])
            ->publiclyActive();
    }

    /**
     * @return array<string, mixed>
     */
    private function offerCard(ScrapedOffer $offer): array
    {
        return [
            'id' => $offer->id,
            'title' => $offer->title,
            'imageUrl' => $this->imageUrl($offer),
            'amount' => $this->formatOfferAmount($offer),
            'price' => $this->formatDecimal($offer->price),
            'unitPrice' => $this->formatUnitPrice($offer),
            'validUntil' => $offer->paper?->active_until?->locale('da')->translatedFormat('l'),
            'fallbackLabel' => mb_substr($offer->title, 0, 12),
            'color' => 'bg-[#f5f3ee]',
        ];
    }

    private function formatDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 2, ',', '');
    }

    private function imageUrl(ScrapedOffer $offer): ?string
    {
        return $offer->image_url
            ?: $offer->grocerProduct?->image_url
            ?: $offer->productMatch?->canonicalProduct?->image_url;
    }

    private function formatOfferAmount(ScrapedOffer $offer): ?string
    {
        if ($offer->package_amount === null) {
            return null;
        }

        $amount = (float) $offer->package_amount;
        $formattedAmount = floor($amount) === $amount
            ? number_format($amount, 0, ',', '')
            : rtrim(rtrim(number_format($amount, 3, ',', ''), '0'), ',');

        return trim($formattedAmount.' '.($offer->package_unit_original ?: $offer->package_unit));
    }

    private function formatUnitPrice(ScrapedOffer $offer): ?string
    {
        if ($offer->unit_price === null || $offer->compare_unit === null) {
            return null;
        }

        return $this->formatDecimal($offer->unit_price).'/'.mb_strtoupper($offer->compare_unit);
    }
}
