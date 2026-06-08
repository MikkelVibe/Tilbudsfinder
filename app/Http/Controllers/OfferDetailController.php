<?php

namespace App\Http\Controllers;

use App\Models\GrocerProduct;
use App\Models\PriceObservation;
use App\Models\ScrapedOffer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class OfferDetailController extends Controller
{
    private const HISTORY_MATCH_CONFIDENCE = 90;

    /**
     * @var list<string>
     */
    private array $chartColors = ['#b3261e', '#173124', '#6f746d', '#8b5e34', '#355c7d', '#7a3b2e'];

    public function show(ScrapedOffer $scrapedOffer): Response
    {
        $scrapedOffer->load([
            'grocer:id,name',
            'paper:id,active_from,active_until',
            'grocerProduct',
            'productMatch.canonicalProduct:id,name,brand,image_url',
        ]);

        return Inertia::render('Offers/Show', [
            'product' => $this->productPayload($scrapedOffer),
            'recommendations' => $this->recommendations($scrapedOffer),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(ScrapedOffer $offer): array
    {
        return [
            'store' => $offer->grocer?->name,
            'name' => $offer->title,
            'description' => $this->description($offer),
            'imageUrl' => $offer->image_url ?: $offer->grocerProduct?->image_url ?: $offer->productMatch?->canonicalProduct?->image_url,
            'currentOffer' => [
                'price' => $this->formatPrice($offer->price),
                'unitPrice' => $this->formatUnitPrice($offer),
                'normalPrice' => null,
                'validUntil' => $offer->paper?->active_until?->locale('da')->translatedFormat('l \\d. j. F'),
            ],
            'nutrition' => $this->nutrition($offer->grocerProduct),
            'history' => $this->history($offer),
        ];
    }

    private function description(ScrapedOffer $offer): ?string
    {
        return collect([
            $this->formatOfferAmount($offer),
            $offer->description ?: $offer->grocerProduct?->description,
        ])->filter()->join(' - ') ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function nutrition(?GrocerProduct $product): array
    {
        if ($product === null) {
            return [];
        }

        return collect([
            $this->nutritionRow('Energi', collect([
                $product->energy_kj_per_100 === null ? null : $this->formatDecimal($product->energy_kj_per_100, 0).' kJ',
                $product->energy_kcal_per_100 === null ? null : $this->formatDecimal($product->energy_kcal_per_100, 0).' kcal',
            ])->filter()->join(' / ')),
            $this->nutritionRow('Fedt', $this->formatGram($product->fat_g_per_100), [
                $this->nutritionRow('heraf mættede fedtsyrer', $this->formatGram($product->saturated_fat_g_per_100)),
            ]),
            $this->nutritionRow('Kulhydrat', $this->formatGram($product->carbohydrate_g_per_100), [
                $this->nutritionRow('heraf sukkerarter', $this->formatGram($product->sugars_g_per_100)),
            ]),
            $this->nutritionRow('Kostfibre', $this->formatGram($product->fiber_g_per_100)),
            $this->nutritionRow('Protein', $this->formatGram($product->protein_g_per_100)),
            $this->nutritionRow('Salt', $this->formatGram($product->salt_g_per_100)),
        ])->filter()->values()->all();
    }

    /**
     * @param  list<array<string, string>|null>  $subItems
     * @return array<string, mixed>|null
     */
    private function nutritionRow(string $label, ?string $value, array $subItems = []): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $row = [
            'label' => $label,
            'value' => $value,
        ];

        $subItems = collect($subItems)->filter()->values()->all();

        if ($subItems !== []) {
            $row['subItems'] = $subItems;
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(ScrapedOffer $offer): array
    {
        $match = $offer->productMatch;

        if ($match?->status === 'matched' && $match->confidence >= self::HISTORY_MATCH_CONFIDENCE) {
            $observations = PriceObservation::query()
                ->select(['id', 'canonical_product_id', 'grocer_id', 'price', 'observed_at', 'valid_from'])
                ->with('grocer:id,name')
                ->where('canonical_product_id', $match->canonical_product_id)
                ->whereNotNull('price')
                ->where('observed_at', '>=', now()->subYear())
                ->orderBy('valid_from')
                ->orderBy('observed_at')
                ->limit(500)
                ->get();

            $series = $this->historySeries($observations);

            if ($series !== []) {
                return $series;
            }
        }

        return [[
            'grocer' => $offer->grocer?->name ?: 'Butik',
            'color' => $this->chartColors[0],
            'prices' => [[
                'date' => ($offer->paper?->active_from ?: $offer->created_at)->toDateString(),
                'price' => (float) $offer->price,
            ]],
        ]];
    }

    /**
     * @param  Collection<int, PriceObservation>  $observations
     * @return list<array<string, mixed>>
     */
    private function historySeries(Collection $observations): array
    {
        return $observations
            ->groupBy('grocer_id')
            ->values()
            ->map(function (Collection $grocerObservations, int $index): array {
                /** @var PriceObservation $first */
                $first = $grocerObservations->first();

                return [
                    'grocer' => $first->grocer?->name ?: 'Butik',
                    'color' => $this->chartColors[$index % count($this->chartColors)],
                    'prices' => $grocerObservations
                        ->map(fn (PriceObservation $observation): array => [
                            'date' => ($observation->valid_from ?: $observation->observed_at)->toDateString(),
                            'price' => (float) $observation->price,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recommendations(ScrapedOffer $offer): array
    {
        $terms = collect(preg_split('/\s+/u', mb_strtolower($offer->title)) ?: [])
            ->map(fn (string $term): string => trim($term, ' ,.-_()[]{}'))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 4)
            ->take(3)
            ->values();

        return ScrapedOffer::query()
            ->select(['id', 'paper_id', 'title', 'image_url', 'price', 'package_amount', 'package_unit_original', 'package_unit'])
            ->with('paper:id,active_from,active_until')
            ->whereKeyNot($offer->id)
            ->whereNotNull('price')
            ->whereHas('paper', function (Builder $query): void {
                $query
                    ->where('active_from', '<=', now())
                    ->where('active_until', '>=', now());
            })
            ->where(function (Builder $query) use ($terms, $offer): void {
                if ($terms->isEmpty()) {
                    $query->where('title', 'ilike', mb_substr($offer->title, 0, 8).'%');

                    return;
                }

                $terms->each(fn (string $term) => $query->orWhere('title', 'ilike', '%'.$term.'%'));
            })
            ->latest()
            ->limit(4)
            ->get()
            ->map(fn (ScrapedOffer $recommendation): array => [
                'id' => $recommendation->id,
                'brand' => mb_substr($recommendation->title, 0, 10),
                'name' => $recommendation->title,
                'weight' => $this->formatOfferAmount($recommendation) ?: 'Ukendt mængde',
                'price' => $this->formatPrice($recommendation->price),
                'imageUrl' => $recommendation->image_url,
            ])
            ->values()
            ->all();
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

        return $this->formatDecimal($offer->unit_price).' kr. pr. '.mb_strtolower($offer->compare_unit);
    }

    private function formatPrice(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->formatDecimal($value);
    }

    private function formatGram(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->formatDecimal($value).'g';
    }

    private function formatDecimal(mixed $value, int $decimals = 2): string
    {
        $number = (float) $value;

        if ($decimals === 2 && floor($number) === $number) {
            $decimals = 0;
        }

        return number_format($number, $decimals, ',', '');
    }
}
