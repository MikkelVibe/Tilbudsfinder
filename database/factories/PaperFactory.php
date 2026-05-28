<?php

namespace Database\Factories;

use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\Paper;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Paper>
 */
class PaperFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grocer_id' => Grocer::factory(),
            'import_batch_id' => ImportBatch::factory(),
            'source_external_id' => fake()->uuid(),
            'title' => 'Uge '.now()->weekOfYear,
            'active_from' => now()->startOfDay(),
            'active_until' => now()->addWeek()->endOfDay(),
        ];
    }
}
