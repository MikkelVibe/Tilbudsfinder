<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Models\Concerns\UsesUuid;
use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['grocer_id', 'scrape_job_id', 'status', 'source_type', 'source_url', 'source_external_id', 'raw_payload_path', 'raw_payload_sha256', 'raw_payload_size_bytes', 'raw_payload_retained_until', 'parsed_offer_count', 'published_offer_count', 'normalization_failure_count', 'started_at', 'finished_at', 'failure_reason', 'metadata'])]
class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory, UsesUuid;

    public function grocer(): BelongsTo
    {
        return $this->belongsTo(Grocer::class);
    }

    public function scrapeJob(): BelongsTo
    {
        return $this->belongsTo(ScrapeJob::class);
    }

    public function papers(): HasMany
    {
        return $this->hasMany(Paper::class);
    }

    public function scrapedOffers(): HasMany
    {
        return $this->hasMany(ScrapedOffer::class);
    }

    public function normalizationFailures(): HasMany
    {
        return $this->hasMany(NormalizationFailure::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'raw_payload_size_bytes' => 'integer',
            'raw_payload_retained_until' => 'immutable_datetime',
            'parsed_offer_count' => 'integer',
            'published_offer_count' => 'integer',
            'normalization_failure_count' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
