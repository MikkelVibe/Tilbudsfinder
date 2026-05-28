<?php

namespace Database\Factories;

use App\Enums\ImportBatchStatus;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\ScrapeJob;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grocer_id' => Grocer::factory(),
            'scrape_job_id' => ScrapeJob::factory(),
            'status' => ImportBatchStatus::Pending,
            'source_type' => 'json',
            'source_url' => fake()->url(),
            'source_external_id' => fake()->uuid(),
            'raw_payload_path' => null,
            'raw_payload_sha256' => null,
            'raw_payload_size_bytes' => null,
            'raw_payload_retained_until' => null,
            'parsed_offer_count' => 0,
            'published_offer_count' => 0,
            'normalization_failure_count' => 0,
            'started_at' => null,
            'finished_at' => null,
            'failure_reason' => null,
            'metadata' => null,
        ];
    }
}
