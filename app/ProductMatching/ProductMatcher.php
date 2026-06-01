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
        return CanonicalProduct::create([
            'name' => $offer->title,
            'brand' => $this->metadataString($offer, 'brand'),
            'package_amount' => $offer->package_amount,
            'package_unit' => $offer->package_unit,
            'compare_unit' => $offer->compare_unit,
            'image_url' => $offer->image_url,
            'status' => 'active',
            'match_confidence' => 100,
        ]);
    }

    private function fillMissingProductFields(CanonicalProduct $product, ScrapedOffer $offer): void
    {
        $attributes = array_filter([
            'brand' => $product->brand === null ? $this->metadataString($offer, 'brand') : null,
            'package_amount' => $product->package_amount === null ? $offer->package_amount : null,
            'package_unit' => $product->package_unit === null ? $offer->package_unit : null,
            'compare_unit' => $product->compare_unit === null ? $offer->compare_unit : null,
            'image_url' => $product->image_url === null ? $offer->image_url : null,
        ], static fn (mixed $value): bool => $value !== null);

        if ($attributes !== []) {
            $product->update($attributes);
        }
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

        return $warnings;
    }

    private function metadataString(ScrapedOffer $offer, string $key): ?string
    {
        $sourceKey = $key === 'brand' ? 'hf2' : $key;
        $value = Arr::get($offer->source_payload, "catalog_product.{$sourceKey}") ?? Arr::get($offer->source_payload, "algolia.{$sourceKey}");

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
