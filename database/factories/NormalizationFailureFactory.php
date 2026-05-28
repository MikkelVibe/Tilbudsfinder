<?php

namespace Database\Factories;

use App\Enums\NormalizationFailureSeverity;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\NormalizationFailure;
use App\Models\ScrapedOffer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NormalizationFailure>
 */
class NormalizationFailureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grocer_id' => Grocer::factory(),
            'import_batch_id' => ImportBatch::factory(),
            'scraped_offer_id' => ScrapedOffer::factory(),
            'severity' => NormalizationFailureSeverity::Warning,
            'field' => 'package_unit',
            'code' => 'unit_unknown',
            'message' => 'Could not normalize package unit.',
            'context' => [
                'raw_unit' => 'unknown',
            ],
        ];
    }
}
