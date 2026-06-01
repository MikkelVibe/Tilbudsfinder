<?php

namespace Database\Factories;

use App\Models\Grocer;
use App\Models\GrocerProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GrocerProduct>
 */
class GrocerProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'grocer_id' => Grocer::factory(),
            'source_product_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'name' => fake()->words(3, true),
            'brand' => fake()->optional()->company(),
            'category' => fake()->optional()->word(),
            'subcategory' => fake()->optional()->word(),
            'description' => fake()->optional()->sentence(),
            'image_url' => fake()->optional()->imageUrl(),
            'package_amount' => fake()->optional()->randomFloat(3, 1, 1000),
            'package_unit' => fake()->optional()->randomElement(['g', 'kg', 'ml', 'l', 'stk']),
            'compare_unit' => fake()->optional()->randomElement(['kg', 'l', 'stk']),
        ];
    }
}
