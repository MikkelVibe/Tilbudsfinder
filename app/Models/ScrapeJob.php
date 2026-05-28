<?php

namespace App\Models;

use App\Enums\ScrapeJobStatus;
use App\Models\Concerns\UsesUuid;
use Database\Factories\ScrapeJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['grocer_id', 'scraper_agent_id', 'status', 'attempt', 'max_attempts', 'scheduled_for', 'leased_until', 'started_at', 'finished_at', 'failure_reason', 'context'])]
class ScrapeJob extends Model
{
    /** @use HasFactory<ScrapeJobFactory> */
    use HasFactory, UsesUuid;

    public function grocer(): BelongsTo
    {
        return $this->belongsTo(Grocer::class);
    }

    public function scraperAgent(): BelongsTo
    {
        return $this->belongsTo(ScraperAgent::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ScrapeJobStatus::class,
            'attempt' => 'integer',
            'max_attempts' => 'integer',
            'scheduled_for' => 'immutable_datetime',
            'leased_until' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'context' => 'array',
        ];
    }
}
