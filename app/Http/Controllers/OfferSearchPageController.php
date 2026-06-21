<?php

namespace App\Http\Controllers;

use App\Http\Requests\OfferSearchPageRequest;
use App\Models\Grocer;
use App\Models\OfferSearchDocument;
use App\Search\OfferSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class OfferSearchPageController extends Controller
{
    public function __invoke(OfferSearchPageRequest $request, OfferSearch $search): Response
    {
        $results = $search->search(
            query: $request->queryText(),
            grocerSlugs: $request->grocerSlugs(),
            sort: $request->sort(),
            perPage: 24,
            priceMin: $request->priceMin(),
            priceMax: $request->priceMax(),
        );

        return Inertia::render('Offers/Index', [
            'filters' => [
                'q' => $request->queryText() ?? '',
                'grocers' => $request->grocerSlugs(),
                'sort' => $request->sort(),
                'price_min' => $request->priceMin(),
                'price_max' => $request->priceMax(),
            ],
            'grocers' => $this->grocers(),
            'results' => $this->results($results),
            'sortOptions' => [
                ['value' => 'relevance', 'label' => 'Relevans'],
                ['value' => 'price_asc', 'label' => 'Laveste pris'],
                ['value' => 'unit_price_asc', 'label' => 'Enhedspris'],
                ['value' => 'price_desc', 'label' => 'Højeste pris'],
            ],
            'quickSearches' => ['mælk', 'kaffe', 'kylling', 'smør', 'pasta'],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function grocers(): array
    {
        $activeCounts = OfferSearchDocument::query()
            ->selectRaw('grocer_slug, count(*) as offer_count')
            ->where('active_from', '<=', now())
            ->where('active_until', '>=', now())
            ->groupBy('grocer_slug')
            ->pluck('offer_count', 'grocer_slug');

        return Grocer::query()
            ->select(['slug', 'name'])
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Grocer $grocer): bool => (int) ($activeCounts[$grocer->slug] ?? 0) > 0)
            ->map(fn (Grocer $grocer): array => [
                'slug' => $grocer->slug,
                'name' => $grocer->name,
                'count' => (int) ($activeCounts[$grocer->slug] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function results(LengthAwarePaginator $results): array
    {
        return [
            'data' => collect($results->items())
                ->map(fn (OfferSearchDocument $document): array => $this->offer($document))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page' => $results->lastPage(),
                'per_page' => $results->perPage(),
                'total' => $results->total(),
                'from' => $results->firstItem(),
                'to' => $results->lastItem(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(OfferSearchDocument $document): array
    {
        return [
            'id' => $document->scraped_offer_id,
            'title' => $document->canonical_product_name ?: $document->title,
            'brand' => $document->brand,
            'imageUrl' => $document->image_url,
            'fallbackLabel' => mb_substr($document->title, 0, 10),
            'grocerName' => $document->grocer_name,
            'grocerSlug' => $document->grocer_slug,
            'canonicalProductId' => $document->canonical_product_id,
            'productOfferCount' => (int) ($document->product_offer_count ?? 1),
            'productStoreCount' => (int) ($document->product_store_count ?? 1),
            'price' => $this->formatDecimal($document->price),
            'priceValue' => (float) $document->price,
            'amount' => $this->formatOfferAmount($document),
            'unitPrice' => $this->formatUnitPrice($document),
            'validUntil' => $document->active_until?->locale('da')->translatedFormat('l'),
        ];
    }

    private function formatDecimal(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 2, ',', '');
    }

    private function formatOfferAmount(OfferSearchDocument $document): ?string
    {
        if ($document->package_amount === null || $document->package_unit === null) {
            return null;
        }

        $amount = (float) $document->package_amount;
        $formattedAmount = floor($amount) === $amount
            ? number_format($amount, 0, ',', '')
            : rtrim(rtrim(number_format($amount, 3, ',', ''), '0'), ',');

        return trim($formattedAmount.' '.mb_strtoupper($document->package_unit));
    }

    private function formatUnitPrice(OfferSearchDocument $document): ?string
    {
        if ($document->unit_price === null || $document->compare_unit === null) {
            return null;
        }

        return $this->formatDecimal($document->unit_price).' kr/'.mb_strtolower($document->compare_unit);
    }
}
