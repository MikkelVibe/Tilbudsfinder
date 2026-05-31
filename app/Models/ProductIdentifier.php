<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Database\Factories\ProductIdentifierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['canonical_product_id', 'grocer_id', 'type', 'value'])]
class ProductIdentifier extends Model
{
    /** @use HasFactory<ProductIdentifierFactory> */
    use HasFactory, UsesUuid;

    public function canonicalProduct(): BelongsTo
    {
        return $this->belongsTo(CanonicalProduct::class);
    }

    public function grocer(): BelongsTo
    {
        return $this->belongsTo(Grocer::class);
    }
}
