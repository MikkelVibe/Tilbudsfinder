<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OfferSearchDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfferSearchResultResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OfferSearchDocument $document */
        $document = $this->resource;

        return [
            'id' => $document->scraped_offer_id,
            'title' => $document->title,
            'brand' => $document->brand,
            'description' => $document->description,
            'image_url' => $document->image_url,
            'grocer' => [
                'id' => $document->grocer_id,
                'slug' => $document->grocer_slug,
                'name' => $document->grocer_name,
            ],
            'price' => $document->price,
            'currency' => $document->currency,
            'package_amount' => $document->package_amount,
            'package_unit' => $document->package_unit,
            'compare_unit' => $document->compare_unit,
            'unit_price' => $document->unit_price,
            'category' => $document->category,
            'subcategory' => $document->subcategory,
            'active_from' => $document->active_from?->toIso8601String(),
            'active_until' => $document->active_until?->toIso8601String(),
            'relevance_score' => isset($document->relevance_score) ? (float) $document->relevance_score : null,
        ];
    }
}
