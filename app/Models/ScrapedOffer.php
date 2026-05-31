<?php

namespace App\Models;

use App\Enums\NormalizationStatus;
use App\Models\Concerns\UsesUuid;
use Database\Factories\ScrapedOfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['grocer_id', 'import_batch_id', 'paper_id', 'source_offer_id', 'source_product_id', 'title', 'description', 'image_url', 'price', 'currency', 'package_amount', 'package_unit_original', 'package_unit', 'compare_unit', 'unit_price', 'normalization_status', 'normalization_confidence', 'source_payload'])]
class ScrapedOffer extends Model
{
    /** @use HasFactory<ScrapedOfferFactory> */
    use HasFactory, UsesUuid;

    public function grocer(): BelongsTo
    {
        return $this->belongsTo(Grocer::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(Paper::class);
    }

    public function normalizationFailures(): HasMany
    {
        return $this->hasMany(NormalizationFailure::class);
    }

    public function productMatch(): HasOne
    {
        return $this->hasOne(ProductMatch::class);
    }

    public function priceObservation(): HasOne
    {
        return $this->hasOne(PriceObservation::class);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'package_amount' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'normalization_status' => NormalizationStatus::class,
            'normalization_confidence' => 'integer',
            'source_payload' => 'array',
        ];
    }
}
