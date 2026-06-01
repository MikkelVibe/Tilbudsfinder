<?php

namespace App\Scrapers\Salling;

use Illuminate\Support\Arr;

class SallingOfferEnricher
{
    public function __construct(
        private readonly SallingCatalogClient $catalogClient = new SallingCatalogClient,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $offers
     * @return list<array<string, mixed>>
     */
    public function enrich(string $catalogKey, array $offers): array
    {
        $result = $this->catalogClient->fetch($catalogKey);
        $index = $this->index($result->products, $catalogKey);

        return array_map(function (array $offer) use ($index): array {
            $key = $this->offerKey($offer);

            if ($key === null || ! isset($index[$key]) || count($index[$key]) !== 1) {
                return $offer;
            }

            $product = $index[$key][0];

            return [
                ...$offer,
                '_salling_enrichment' => [
                    'source' => $product->source,
                    'source_product_id' => $product->sourceProductId,
                    'title' => $product->title,
                    'brand' => $product->brand,
                    'package_text' => $product->packageText,
                    'eans' => $product->eans,
                    'match_method' => 'exact_title_and_price',
                ],
            ];
        }, $offers);
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     */
    public function enrichedCount(array $offers): int
    {
        return count(array_filter(
            $offers,
            static fn (array $offer): bool => is_array(Arr::get($offer, '_salling_enrichment.eans')) && Arr::get($offer, '_salling_enrichment.eans') !== [],
        ));
    }

    /**
     * @param  list<SallingCatalogProduct>  $products
     * @return array<string, list<SallingCatalogProduct>>
     */
    private function index(array $products, string $catalogKey): array
    {
        $index = [];

        foreach ($products as $product) {
            if ($product->eans === []) {
                continue;
            }

            $priceCents = $this->productPriceCents($product, $catalogKey);

            if ($priceCents === null) {
                continue;
            }

            $key = $this->key($product->title, $priceCents);
            $index[$key][] = $product;
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function offerKey(array $offer): ?string
    {
        $heading = Arr::get($offer, 'heading');
        $price = Arr::get($offer, 'pricing.price');

        if (! is_string($heading) || trim($heading) === '' || ! is_numeric($price)) {
            return null;
        }

        return $this->key($heading, $this->priceCents($price));
    }

    private function key(string $title, int $priceCents): string
    {
        return $this->normalizeTitle($title).'|'.$priceCents;
    }

    private function normalizeTitle(string $title): string
    {
        $title = mb_strtolower($title);
        $title = preg_replace('/[^\pL\pN]+/u', ' ', $title) ?? $title;

        return trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
    }

    private function priceCents(mixed $price): int
    {
        return (int) round((float) $price * 100);
    }

    private function productPriceCents(SallingCatalogProduct $product, string $catalogKey): ?int
    {
        return match ($catalogKey) {
            'bilkatogo' => $this->bilkaToGoPriceCents($product),
            'foetex' => is_numeric(Arr::get($product->payload, 'sales_price')) ? $this->priceCents(Arr::get($product->payload, 'sales_price')) : null,
            default => null,
        };
    }

    private function bilkaToGoPriceCents(SallingCatalogProduct $product): ?int
    {
        $storeData = Arr::get($product->payload, 'storeData');

        if (! is_array($storeData)) {
            return null;
        }

        foreach ($storeData as $store) {
            $price = is_array($store) ? Arr::get($store, 'price') : null;

            if (is_numeric($price) && (int) $price > 0) {
                return (int) $price;
            }
        }

        return null;
    }
}
