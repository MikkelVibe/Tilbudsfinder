<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Database\Factories\CanonicalProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'brand', 'package_amount', 'package_unit', 'compare_unit', 'image_url', 'status', 'match_confidence', 'declaration', 'nutrition_info_raw', 'nutrition_basis_unit', 'energy_kj_per_100', 'energy_kcal_per_100', 'fat_g_per_100', 'saturated_fat_g_per_100', 'carbohydrate_g_per_100', 'sugars_g_per_100', 'fiber_g_per_100', 'protein_g_per_100', 'salt_g_per_100'])]
class CanonicalProduct extends Model
{
    /** @use HasFactory<CanonicalProductFactory> */
    use HasFactory, UsesUuid;

    public function identifiers(): HasMany
    {
        return $this->hasMany(ProductIdentifier::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ProductMatch::class);
    }

    public function priceObservations(): HasMany
    {
        return $this->hasMany(PriceObservation::class);
    }

    protected function casts(): array
    {
        return [
            'package_amount' => 'decimal:3',
            'match_confidence' => 'integer',
            'nutrition_info_raw' => 'array',
            'energy_kj_per_100' => 'decimal:2',
            'energy_kcal_per_100' => 'decimal:2',
            'fat_g_per_100' => 'decimal:2',
            'saturated_fat_g_per_100' => 'decimal:2',
            'carbohydrate_g_per_100' => 'decimal:2',
            'sugars_g_per_100' => 'decimal:2',
            'fiber_g_per_100' => 'decimal:2',
            'protein_g_per_100' => 'decimal:2',
            'salt_g_per_100' => 'decimal:2',
        ];
    }
}
