<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scraped_offer_id', 'grocer_id', 'paper_id', 'canonical_product_id', 'canonical_product_name', 'product_match_confidence', 'grocer_slug', 'grocer_name', 'title', 'brand', 'category', 'subcategory', 'description', 'image_url', 'search_text', 'price', 'package_amount', 'package_unit', 'compare_unit', 'unit_price', 'currency', 'active_from', 'active_until'])]
class OfferSearchDocument extends Model
{
    use UsesUuid;

    public function scrapedOffer(): BelongsTo
    {
        return $this->belongsTo(ScrapedOffer::class);
    }

    public function grocer(): BelongsTo
    {
        return $this->belongsTo(Grocer::class);
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(Paper::class);
    }

    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'package_amount' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'product_match_confidence' => 'integer',
            'active_from' => 'immutable_datetime',
            'active_until' => 'immutable_datetime',
        ];
    }
}
