<?php

namespace Database\Factories;

use App\Enums\GrocerHealthStatus;
use App\Models\Grocer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Grocer>
 */
class GrocerFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'name' => $name,
            'website_url' => fake()->url(),
            'is_enabled' => true,
            'health_status' => GrocerHealthStatus::Healthy,
            'last_success_at' => null,
            'last_failure_at' => null,
            'next_expected_import_at' => now()->addDay(),
        ];
    }
}
