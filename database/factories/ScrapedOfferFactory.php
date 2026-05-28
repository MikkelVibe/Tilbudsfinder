<?php

namespace Database\Factories;

use App\Enums\NormalizationStatus;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrapedOffer>
 */
class ScrapedOfferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grocer_id' => Grocer::factory(),
            'import_batch_id' => ImportBatch::factory(),
            'paper_id' => Paper::factory(),
            'source_offer_id' => fake()->uuid(),
            'source_product_id' => fake()->uuid(),
            'source_hash' => hash('sha256', fake()->uuid()),
            'source_position' => fake()->numberBetween(1, 300),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'image_url' => fake()->imageUrl(),
            'price' => fake()->randomFloat(2, 1, 200),
            'currency' => 'DKK',
            'package_amount' => fake()->randomFloat(3, 0.1, 5),
            'package_unit_original' => '500 GR.',
            'package_unit' => 'g',
            'compare_unit' => 'kg',
            'unit_price' => fake()->randomFloat(2, 1, 400),
            'normalization_status' => NormalizationStatus::Succeeded,
            'normalization_confidence' => 95,
            'source_payload' => [
                'fixture' => true,
            ],
        ];
    }
}
