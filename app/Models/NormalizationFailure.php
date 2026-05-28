<?php

namespace App\Models;

use App\Enums\NormalizationFailureSeverity;
use App\Models\Concerns\UsesUuid;
use Database\Factories\NormalizationFailureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['grocer_id', 'import_batch_id', 'scraped_offer_id', 'severity', 'field', 'code', 'message', 'context'])]
class NormalizationFailure extends Model
{
    /** @use HasFactory<NormalizationFailureFactory> */
    use HasFactory, UsesUuid;

    public function grocer(): BelongsTo
    {
        return $this->belongsTo(Grocer::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function scrapedOffer(): BelongsTo
    {
        return $this->belongsTo(ScrapedOffer::class);
    }

    protected function casts(): array
    {
        return [
            'severity' => NormalizationFailureSeverity::class,
            'context' => 'array',
        ];
    }
}
