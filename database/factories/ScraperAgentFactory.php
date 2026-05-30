<?php

namespace Database\Factories;

use App\Enums\ScraperAgentStatus;
use App\Models\ScraperAgent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ScraperAgent>
 */
class ScraperAgentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'token_hash' => hash('sha256', fake()->sha256()),
            'status' => ScraperAgentStatus::Active,
            'app_version' => 'test',
            'last_seen_at' => now(),
            'last_heartbeat_at' => now(),
            'metadata' => [
                'host' => fake()->domainWord(),
            ],
        ];
    }
}
