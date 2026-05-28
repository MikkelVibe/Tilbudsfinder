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
            'status' => ScraperAgentStatus::Active,
            'last_seen_at' => now(),
            'metadata' => [
                'host' => fake()->domainWord(),
            ],
        ];
    }
}
