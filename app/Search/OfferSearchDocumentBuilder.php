<?php

namespace App\Search;

use App\Models\ImportBatch;
use App\Models\OfferSearchDocument;
use App\Models\ScrapedOffer;
use Illuminate\Support\Str;

class OfferSearchDocumentBuilder
{
    public function rebuildForImportBatch(ImportBatch $batch): void
    {
        ScrapedOffer::query()
            ->with(['grocer', 'paper', 'grocerProduct'])
            ->where('import_batch_id', $batch->id)
            ->each(fn (ScrapedOffer $offer): mixed => $this->updateForOffer($offer));
    }

    public function updateForOffer(ScrapedOffer $offer): OfferSearchDocument
    {
        $offer->loadMissing(['grocer', 'paper', 'grocerProduct']);

        $grocerProduct = $offer->grocerProduct;
        $brand = $grocerProduct?->brand;
        $category = $grocerProduct?->category;
        $subcategory = $grocerProduct?->subcategory;
        $description = $grocerProduct?->description ?? $offer->description;

        return OfferSearchDocument::query()->updateOrCreate(
            ['scraped_offer_id' => $offer->id],
            [
                'grocer_id' => $offer->grocer_id,
                'paper_id' => $offer->paper_id,
                'grocer_slug' => $offer->grocer->slug,
                'grocer_name' => $offer->grocer->name,
                'title' => $offer->title,
                'brand' => $brand,
                'category' => $category,
                'subcategory' => $subcategory,
                'description' => $description,
                'image_url' => $grocerProduct?->image_url ?? $offer->image_url,
                'search_text' => $this->searchText($offer->title, $brand, $category, $subcategory, $description),
                'price' => $offer->price,
                'package_amount' => $offer->package_amount,
                'package_unit' => $offer->package_unit,
                'compare_unit' => $offer->compare_unit,
                'unit_price' => $offer->unit_price,
                'currency' => $offer->currency,
                'active_from' => $offer->paper->active_from,
                'active_until' => $offer->paper->active_until,
            ],
        );
    }

    private function searchText(?string ...$parts): string
    {
        return Str::of(implode(' ', array_filter($parts, static fn (?string $part): bool => filled($part))))
            ->squish()
            ->toString();
    }
}
