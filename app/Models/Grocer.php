<?php

namespace App\Models;

use App\Enums\GrocerHealthStatus;
use App\Models\Concerns\UsesUuid;
use Database\Factories\GrocerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name', 'website_url', 'is_enabled', 'health_status', 'last_success_at', 'last_failure_at', 'next_expected_import_at'])]
class Grocer extends Model
{
    /** @use HasFactory<GrocerFactory> */
    use HasFactory, UsesUuid;

    public function scrapeJobs(): HasMany
    {
        return $this->hasMany(ScrapeJob::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function papers(): HasMany
    {
        return $this->hasMany(Paper::class);
    }

    public function scrapedOffers(): HasMany
    {
        return $this->hasMany(ScrapedOffer::class);
    }

    public function grocerProducts(): HasMany
    {
        return $this->hasMany(GrocerProduct::class);
    }

    public function normalizationFailures(): HasMany
    {
        return $this->hasMany(NormalizationFailure::class);
    }

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'health_status' => GrocerHealthStatus::class,
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'next_expected_import_at' => 'immutable_datetime',
        ];
    }
}
