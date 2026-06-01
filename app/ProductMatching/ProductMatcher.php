<?php

namespace App\ProductMatching;

use App\Models\CanonicalProduct;
use App\Models\ImportBatch;
use App\Models\PriceObservation;
use App\Models\ProductIdentifier;
use App\Models\ProductMatch;
use App\Models\ScrapedOffer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProductMatcher
{
    /**
     * @return array{matched: int, ambiguous: int, skipped: int, conflicts: int}
     */
    public function matchImportBatch(ImportBatch $batch): array
    {
        $result = ['matched' => 0, 'ambiguous' => 0, 'skipped' => 0, 'conflicts' => 0];

        $batch->scrapedOffers()
            ->with(['grocer', 'paper', 'productMatch'])
            ->orderBy('id')
            ->each(function (ScrapedOffer $offer) use (&$result): void {
                $status = $this->matchOffer($offer);
                $result[$status]++;
            });

        return $result;
    }

    private function matchOffer(ScrapedOffer $offer): string
    {
        if ($offer->productMatch !== null) {
            return 'skipped';
        }

        $barcodes = $this->barcodes($offer);

        if (count($barcodes) === 0) {
            $this->createUnmatchedProductMatch($offer, 'no_barcode');

            return 'skipped';
        }

        return DB::transaction(fn (): string => $this->matchBarcodeOffer($offer, $barcodes));
    }

    /**
     * @param  list<string>  $barcodes
     */
    private function matchBarcodeOffer(ScrapedOffer $offer, array $barcodes): string
    {
        $identifiers = ProductIdentifier::query()
            ->where('type', 'ean')
            ->whereIn('value', $barcodes)
            ->with('canonicalProduct')
            ->get();

        $matchedProductIds = $identifiers->pluck('canonical_product_id')->unique()->values();
        $product = $identifiers->first()?->canonicalProduct ?? $this->createCanonicalProduct($offer);

        foreach ($barcodes as $barcode) {
            ProductIdentifier::firstOrCreate([
                'type' => 'ean',
                'value' => $barcode,
            ], [
                'canonical_product_id' => $product->id,
                'grocer_id' => null,
            ]);
        }

        $this->createSourceProductIdentifier($offer, $product);

        $warnings = $this->warnings($offer, $product);

        if (count($barcodes) > 1) {
            $warnings['multiple_barcodes_attached'] = $barcodes;
        }

        if ($matchedProductIds->count() > 1) {
            $warnings['barcode_product_conflict'] = $matchedProductIds->all();
        }

        ProductMatch::create([
            'scraped_offer_id' => $offer->id,
            'canonical_product_id' => $product->id,
            'match_method' => 'ean',
            'confidence' => 100,
            'status' => $matchedProductIds->count() > 1 ? 'conflict_flagged' : 'matched',
            'warnings' => $warnings === [] ? null : $warnings,
        ]);

        PriceObservation::create([
            'canonical_product_id' => $product->id,
            'scraped_offer_id' => $offer->id,
            'grocer_id' => $offer->grocer_id,
            'price' => $offer->price,
            'unit_price' => $offer->unit_price,
            'currency' => $offer->currency,
            'observed_at' => $offer->created_at ?? now(),
            'valid_from' => $offer->paper?->active_from,
            'valid_until' => $offer->paper?->active_until,
        ]);

        $this->fillMissingProductFields($product, $offer);

        return $matchedProductIds->count() > 1 ? 'conflicts' : 'matched';
    }

    private function createCanonicalProduct(ScrapedOffer $offer): CanonicalProduct
    {
        $nutrition = $this->nutrition($offer);

        return CanonicalProduct::create([
            'name' => $offer->title,
            'brand' => $this->metadataString($offer, 'brand'),
            'package_amount' => $offer->package_amount,
            'package_unit' => $offer->package_unit,
            'compare_unit' => $offer->compare_unit,
            'image_url' => $offer->image_url,
            'status' => 'active',
            'match_confidence' => 100,
            'declaration' => $this->metadataString($offer, 'declaration'),
            'nutrition_info_raw' => $nutrition,
            'nutrition_basis_unit' => $nutrition === [] ? null : $this->nutritionBasisUnit($offer),
            ...$this->macroColumns($nutrition),
        ]);
    }

    private function fillMissingProductFields(CanonicalProduct $product, ScrapedOffer $offer): void
    {
        $nutrition = $this->nutrition($offer);
        $attributes = array_filter([
            'brand' => $product->brand === null ? $this->metadataString($offer, 'brand') : null,
            'package_amount' => $product->package_amount === null ? $offer->package_amount : null,
            'package_unit' => $product->package_unit === null ? $offer->package_unit : null,
            'compare_unit' => $product->compare_unit === null ? $offer->compare_unit : null,
            'image_url' => $product->image_url === null ? $offer->image_url : null,
            'declaration' => $product->declaration === null ? $this->metadataString($offer, 'declaration') : null,
            'nutrition_info_raw' => $product->nutrition_info_raw === null && $nutrition !== [] ? $nutrition : null,
            'nutrition_basis_unit' => $product->nutrition_basis_unit === null && $nutrition !== [] ? $this->nutritionBasisUnit($offer) : null,
        ], static fn (mixed $value): bool => $value !== null);

        foreach ($this->macroColumns($nutrition) as $key => $value) {
            if ($product->{$key} === null && $value !== null) {
                $attributes[$key] = $value;
            }
        }

        if ($attributes !== []) {
            $product->update($attributes);
        }
    }

    private function createSourceProductIdentifier(ScrapedOffer $offer, CanonicalProduct $product): void
    {
        if ($offer->source_product_id === null) {
            return;
        }

        ProductIdentifier::firstOrCreate([
            'grocer_id' => $offer->grocer_id,
            'type' => 'source_product_id',
            'value' => $offer->source_product_id,
        ], [
            'canonical_product_id' => $product->id,
        ]);
    }

    private function createUnmatchedProductMatch(ScrapedOffer $offer, string $reason): void
    {
        ProductMatch::create([
            'scraped_offer_id' => $offer->id,
            'canonical_product_id' => null,
            'match_method' => 'none',
            'confidence' => 0,
            'status' => 'unmatched',
            'warnings' => ['reason' => $reason],
        ]);
    }

    /**
     * @return list<string>
     */
    private function barcodes(ScrapedOffer $offer): array
    {
        if ($offer->grocer?->slug !== null && in_array($offer->grocer->slug, ['meny', 'spar', 'minkobmand'], true)) {
            return $this->eanLikeValues([$offer->source_product_id]);
        }

        $barcodes = match ($offer->grocer?->slug) {
            'rema1000' => Arr::get($offer->source_payload, 'product_detail.bar_codes')
                ?? Arr::get($offer->source_payload, 'catalog_product.bar_codes')
                ?? Arr::get($offer->source_payload, 'algolia.bar_codes')
                ?? [],
            '365discount', 'kvickly', 'superbrugsen', 'daglibrugsen' => [Arr::get($offer->source_payload, '_incito_product.id')],
            'bilka', 'foetex' => Arr::get($offer->source_payload, '_salling_enrichment.eans', []),
            default => [],
        };

        if (! is_array($barcodes)) {
            return [];
        }

        return $this->eanLikeValues($barcodes);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function eanLikeValues(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $barcode): ?string => is_scalar($barcode) && preg_match('/^\d{8,14}$/', (string) $barcode) === 1 ? (string) $barcode : null,
            $values,
        ))));
    }

    /**
     * @return array<string, mixed>
     */
    private function warnings(ScrapedOffer $offer, CanonicalProduct $product): array
    {
        $warnings = [];

        if ($this->metadataString($offer, 'brand') !== null && $product->brand !== null && $this->metadataString($offer, 'brand') !== $product->brand) {
            $warnings['brand_conflict'] = ['existing' => $product->brand, 'incoming' => $this->metadataString($offer, 'brand')];
        }

        foreach ($this->macroColumns($this->nutrition($offer)) as $key => $incoming) {
            if ($incoming !== null && $product->{$key} !== null && (string) $product->{$key} !== number_format((float) $incoming, 2, '.', '')) {
                $warnings['nutrition_conflict'] = true;

                break;
            }
        }

        return $warnings;
    }

    private function metadataString(ScrapedOffer $offer, string $key): ?string
    {
        $sourceKey = $key === 'brand' ? 'hf2' : $key;
        $value = Arr::get($offer->source_payload, "catalog_product.{$sourceKey}") ?? Arr::get($offer->source_payload, "algolia.{$sourceKey}");

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return list<array{name?: string, value?: string, sort?: string}>
     */
    private function nutrition(ScrapedOffer $offer): array
    {
        $nutrition = Arr::get($offer->source_payload, 'catalog_product.nutrition_info') ?? Arr::get($offer->source_payload, 'product_detail.nutrition_info') ?? [];

        return is_array($nutrition) ? $nutrition : [];
    }

    private function nutritionBasisUnit(ScrapedOffer $offer): ?string
    {
        return match ($offer->compare_unit) {
            'kg' => 'g',
            'l' => 'ml',
            default => null,
        };
    }

    /**
     * @param  list<array{name?: string, value?: string, sort?: string}>  $nutrition
     * @return array<string, string|null>
     */
    private function macroColumns(array $nutrition): array
    {
        return [
            'energy_kj_per_100' => $this->energyValue($nutrition, 'kj'),
            'energy_kcal_per_100' => $this->energyValue($nutrition, 'kcal'),
            'fat_g_per_100' => $this->nutrientValue($nutrition, ['fedt']),
            'saturated_fat_g_per_100' => $this->nutrientValue($nutrition, ['mættede']),
            'carbohydrate_g_per_100' => $this->nutrientValue($nutrition, ['kulhydrat']),
            'sugars_g_per_100' => $this->nutrientValue($nutrition, ['sukkerarter']),
            'fiber_g_per_100' => $this->nutrientValue($nutrition, ['kostfibre', 'fiber']),
            'protein_g_per_100' => $this->nutrientValue($nutrition, ['protein']),
            'salt_g_per_100' => $this->nutrientValue($nutrition, ['salt']),
        ];
    }

    /**
     * @param  list<array{name?: string, value?: string, sort?: string}>  $nutrition
     */
    private function energyValue(array $nutrition, string $unit): ?string
    {
        foreach ($nutrition as $row) {
            if (! is_array($row) || ! str_contains(mb_strtolower((string) ($row['name'] ?? '')), 'energi')) {
                continue;
            }

            if (preg_match($unit === 'kj' ? '/(?<value>\d+(?:[.,]\d+)?)\s*kJ/i' : '/(?<value>\d+(?:[.,]\d+)?)\s*kcal/i', (string) ($row['value'] ?? ''), $matches) === 1) {
                return $this->decimal($matches['value']);
            }
        }

        return null;
    }

    /**
     * @param  list<array{name?: string, value?: string, sort?: string}>  $nutrition
     * @param  list<string>  $nameFragments
     */
    private function nutrientValue(array $nutrition, array $nameFragments): ?string
    {
        foreach ($nutrition as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = mb_strtolower((string) ($row['name'] ?? ''));

            foreach ($nameFragments as $fragment) {
                if (str_contains($name, $fragment)) {
                    return $this->decimal((string) ($row['value'] ?? ''));
                }
            }
        }

        return null;
    }

    private function decimal(string $value): ?string
    {
        if (preg_match('/(?<value>\d+(?:[.,]\d+)?)/', str_replace('.', '', $value), $matches) !== 1) {
            return null;
        }

        return str_replace(',', '.', $matches['value']);
    }
}
