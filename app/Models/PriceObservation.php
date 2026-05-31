<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Database\Factories\PriceObservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['canonical_product_id', 'scraped_offer_id', 'grocer_id', 'price', 'unit_price', 'currency', 'observed_at', 'valid_from', 'valid_until'])]
class PriceObservation extends Model
{
    /** @use HasFactory<PriceObservationFactory> */
    use HasFactory, UsesUuid;

    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    public function scrapedOffer(): BelongsTo
    {
        return $this->belongsTo(ScrapedOffer::class);
    }

    public function grocer(): BelongsTo
    {
        return $this->belongsTo(Grocer::class);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'observed_at' => 'immutable_datetime',
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
        ];
    }
}
