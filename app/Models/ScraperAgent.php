<?php

namespace App\Models;

use App\Enums\ScraperAgentStatus;
use App\Models\Concerns\UsesUuid;
use Database\Factories\ScraperAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'token_hash', 'status', 'app_version', 'last_seen_at', 'last_heartbeat_at', 'metadata'])]
class ScraperAgent extends Model
{
    /** @use HasFactory<ScraperAgentFactory> */
    use HasFactory, UsesUuid;

    public function scrapeJobs(): HasMany
    {
        return $this->hasMany(ScrapeJob::class);
    }

    protected function casts(): array
    {
        return [
            'status' => ScraperAgentStatus::class,
            'last_seen_at' => 'immutable_datetime',
            'last_heartbeat_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
