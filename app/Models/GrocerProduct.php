<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Database\Factories\GrocerProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['grocer_id', 'source_product_id', 'name', 'brand', 'category', 'subcategory', 'description', 'image_url', 'package_amount', 'package_unit', 'compare_unit', 'declaration', 'attributes', 'traceability', 'raw_detail_payload', 'nutrition_basis_unit', 'energy_kj_per_100', 'energy_kcal_per_100', 'fat_g_per_100', 'saturated_fat_g_per_100', 'carbohydrate_g_per_100', 'sugars_g_per_100', 'fiber_g_per_100', 'protein_g_per_100', 'salt_g_per_100', 'detail_observed_at'])]
class GrocerProduct extends Model
{
    /** @use HasFactory<GrocerProductFactory> */
    use HasFactory, UsesUuid;

    public function grocer(): BelongsTo
    {
        return $this->belongsTo(Grocer::class);
    }

    public function scrapedOffers(): HasMany
    {
        return $this->hasMany(ScrapedOffer::class);
    }

    protected function casts(): array
    {
        return [
            'package_amount' => 'decimal:3',
            'attributes' => 'array',
            'traceability' => 'array',
            'raw_detail_payload' => 'array',
            'energy_kj_per_100' => 'decimal:2',
            'energy_kcal_per_100' => 'decimal:2',
            'fat_g_per_100' => 'decimal:2',
            'saturated_fat_g_per_100' => 'decimal:2',
            'carbohydrate_g_per_100' => 'decimal:2',
            'sugars_g_per_100' => 'decimal:2',
            'fiber_g_per_100' => 'decimal:2',
            'protein_g_per_100' => 'decimal:2',
            'salt_g_per_100' => 'decimal:2',
            'detail_observed_at' => 'immutable_datetime',
        ];
    }
}
