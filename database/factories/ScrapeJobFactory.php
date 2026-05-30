<?php

namespace Database\Factories;

use App\Enums\ScrapeJobStatus;
use App\Models\Grocer;
use App\Models\ScrapeJob;
use App\Models\ScraperAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrapeJob>
 */
class ScrapeJobFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grocer_id' => Grocer::factory(),
            'scraper_agent_id' => ScraperAgent::factory(),
            'status' => ScrapeJobStatus::Pending,
            'attempt' => 0,
            'max_attempts' => 3,
            'scheduled_for' => now(),
            'leased_until' => null,
            'started_at' => null,
            'finished_at' => null,
            'payload_received_at' => null,
            'failure_reason' => null,
            'context' => null,
        ];
    }
}
