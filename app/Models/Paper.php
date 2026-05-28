<?php

namespace App\Models;

use App\Models\Concerns\UsesUuid;
use Database\Factories\PaperFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['grocer_id', 'import_batch_id', 'source_external_id', 'title', 'active_from', 'active_until'])]
class Paper extends Model
{
    /** @use HasFactory<PaperFactory> */
    use HasFactory, UsesUuid;

    public function grocer(): BelongsTo
    {
        return $this->belongsTo(Grocer::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function scrapedOffers(): HasMany
    {
        return $this->hasMany(ScrapedOffer::class);
    }

    protected function casts(): array
    {
        return [
            'active_from' => 'immutable_datetime',
            'active_until' => 'immutable_datetime',
        ];
    }
}
