<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Database\Factories\ProductMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['scraped_offer_id', 'canonical_product_id', 'match_method', 'confidence', 'status', 'warnings'])]
class ProductMatch extends Model
{
    /** @use HasFactory<ProductMatchFactory> */
    use HasFactory, UsesUuid;

    public function scrapedOffer(): BelongsTo
    {
        return $this->belongsTo(ScrapedOffer::class);
    }

    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'warnings' => 'array',
        ];
    }
}
