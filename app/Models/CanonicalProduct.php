<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Database\Factories\CanonicalProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'brand', 'package_amount', 'package_unit', 'compare_unit', 'image_url', 'status', 'match_confidence'])]
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
        ];
    }
}
