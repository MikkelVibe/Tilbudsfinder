<?php

namespace App\Http\Controllers;

use App\Models\Grocer;
use App\Models\OfferSearchDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StoreDirectoryController extends Controller
{
    public function __invoke(): Response
    {
        $activeCounts = $this->activeOfferCounts();

        return Inertia::render('Stores/Index', [
            'stores' => $this->stores($activeCounts),
            'summary' => [
                'storeCount' => Grocer::query()->where('is_enabled', true)->count(),
                'activeStoreCount' => $activeCounts->filter(fn (int $count): bool => $count > 0)->count(),
                'offerCount' => $activeCounts->sum(),
            ],
        ]);
    }

    /**
     * @return Collection<string, int>
     */
    private function activeOfferCounts(): Collection
    {
        $groupExpression = $this->productGroupExpression();

        return OfferSearchDocument::query()
            ->selectRaw("grocer_slug, count(distinct {$groupExpression}) as offer_count")
            ->where('active_from', '<=', now())
            ->where('active_until', '>=', now())
            ->whereHas('grocer', fn ($query) => $query->where('is_enabled', true))
            ->groupBy('grocer_slug')
            ->pluck('offer_count', 'grocer_slug')
            ->map(fn (mixed $count): int => (int) $count);
    }

    private function productGroupExpression(): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "coalesce('canonical_' || canonical_product_id::text, 'scraped_' || scraped_offer_id::text)";
        }

        return "coalesce(concat('canonical_', canonical_product_id), concat('scraped_', scraped_offer_id))";
    }

    /**
     * @param  Collection<string, int>  $activeCounts
     * @return list<array<string, mixed>>
     */
    private function stores(Collection $activeCounts): array
    {
        return Grocer::query()
            ->select(['slug', 'name', 'website_url'])
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get()
            ->map(function (Grocer $grocer) use ($activeCounts): array {
                $offerCount = $activeCounts->get($grocer->slug, 0);

                return [
                    'slug' => $grocer->slug,
                    'name' => $grocer->name,
                    'logoUrl' => $this->logoUrl($grocer),
                    'logoNeedsBackdrop' => $this->logoNeedsBackdrop($grocer),
                    'offerCount' => $offerCount,
                    'offerCountLabel' => $offerCount === 1 ? '1 aktivt tilbud' : "{$offerCount} aktive tilbud",
                    'isActive' => $offerCount > 0,
                    'href' => route('offers.index', ['grocers' => [$grocer->slug]], false),
                ];
            })
            ->sortBy([
                ['isActive', 'desc'],
                ['offerCount', 'desc'],
                ['name', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function logoUrl(Grocer $grocer): ?string
    {
        $localLogoUrls = [
            '365discount' => '/store-logos/365discount.png',
            'bilka' => '/store-logos/bilka.png',
            'daglibrugsen' => '/store-logos/daglibrugsen.png',
            'foetex' => '/store-logos/foetex.png',
            'kvickly' => '/store-logos/kvickly.png',
            'meny' => '/store-logos/meny.svg',
            'minkobmand' => '/store-logos/minkobmand.svg',
            'nemlig' => '/store-logos/nemlig.png',
            'netto' => '/store-logos/netto.png',
            'rema1000' => '/store-logos/rema1000.png',
            'spar' => '/store-logos/spar.svg',
            'superbrugsen' => '/store-logos/superbrugsen.png',
        ];

        if (isset($localLogoUrls[$grocer->slug])) {
            return $localLogoUrls[$grocer->slug];
        }

        if (! is_string($grocer->website_url) || trim($grocer->website_url) === '') {
            return null;
        }

        return 'https://www.google.com/s2/favicons?sz=256&domain_url='.rawurlencode($grocer->website_url);
    }

    private function logoNeedsBackdrop(Grocer $grocer): bool
    {
        return in_array($grocer->slug, [
            'minkobmand',
        ], true);
    }
}
