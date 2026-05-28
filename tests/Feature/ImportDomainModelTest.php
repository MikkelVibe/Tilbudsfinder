<?php

namespace Tests\Feature;

use App\Enums\GrocerHealthStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\NormalizationFailureSeverity;
use App\Enums\NormalizationStatus;
use App\Enums\ScrapeJobStatus;
use App\Enums\ScraperAgentStatus;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\NormalizationFailure;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Models\ScrapeJob;
use App\Models\ScraperAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportDomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_domain_uses_uuid_primary_keys_and_relationships(): void
    {
        $grocer = Grocer::factory()->create([
            'slug' => 'rema1000',
            'name' => 'REMA 1000',
        ]);
        $agent = ScraperAgent::factory()->create([
            'slug' => 'apartment-laptop',
        ]);
        $job = ScrapeJob::factory()->for($grocer)->for($agent, 'scraperAgent')->create([
            'status' => ScrapeJobStatus::Running,
        ]);
        $batch = ImportBatch::factory()->for($grocer)->for($job, 'scrapeJob')->create([
            'status' => ImportBatchStatus::Validating,
            'raw_payload_path' => 'imports/raw/rema1000/job-1.json.gz',
            'raw_payload_sha256' => hash('sha256', 'fixture'),
            'raw_payload_size_bytes' => 1234,
            'raw_payload_retained_until' => now()->addDays(90),
        ]);
        $paper = Paper::factory()->for($grocer)->for($batch, 'importBatch')->create();
        $offer = ScrapedOffer::factory()
            ->for($grocer)
            ->for($batch, 'importBatch')
            ->for($paper)
            ->create([
                'title' => 'Arla minimælk',
                'price' => '12.95',
                'package_amount' => '1.000',
                'package_unit_original' => '1 LTR.',
                'package_unit' => 'l',
                'compare_unit' => 'l',
                'unit_price' => '12.95',
                'normalization_status' => NormalizationStatus::Succeeded,
            ]);
        $failure = NormalizationFailure::factory()
            ->for($grocer)
            ->for($batch, 'importBatch')
            ->for($offer, 'scrapedOffer')
            ->create([
                'severity' => NormalizationFailureSeverity::Warning,
            ]);

        $this->assertTrue(Str::isUuid($grocer->id));
        $this->assertTrue(Str::isUuid($job->id));
        $this->assertTrue(Str::isUuid($batch->id));
        $this->assertTrue($grocer->scrapeJobs->first()->is($job));
        $this->assertTrue($agent->scrapeJobs->first()->is($job));
        $this->assertTrue($job->importBatches->first()->is($batch));
        $this->assertTrue($batch->papers->first()->is($paper));
        $this->assertTrue($paper->scrapedOffers->first()->is($offer));
        $this->assertTrue($offer->normalizationFailures->first()->is($failure));
    }

    public function test_status_and_decimal_fields_are_cast_to_expected_types(): void
    {
        $grocer = Grocer::factory()->create([
            'health_status' => GrocerHealthStatus::Stale,
        ]);
        $agent = ScraperAgent::factory()->create([
            'status' => ScraperAgentStatus::Missing,
        ]);
        $job = ScrapeJob::factory()->for($grocer)->for($agent, 'scraperAgent')->create([
            'status' => ScrapeJobStatus::Retrying,
            'context' => ['reason' => 'timeout'],
        ]);
        $batch = ImportBatch::factory()->for($grocer)->for($job, 'scrapeJob')->create([
            'status' => ImportBatchStatus::Quarantined,
        ]);
        $paper = Paper::factory()->for($grocer)->for($batch, 'importBatch')->create();
        $offer = ScrapedOffer::factory()
            ->for($grocer)
            ->for($batch, 'importBatch')
            ->for($paper)
            ->create([
                'price' => '10.50',
                'package_amount' => '0.500',
                'unit_price' => '21.00',
                'normalization_status' => NormalizationStatus::Partial,
            ]);

        $this->assertSame(GrocerHealthStatus::Stale, $grocer->refresh()->health_status);
        $this->assertSame(ScraperAgentStatus::Missing, $agent->refresh()->status);
        $this->assertSame(ScrapeJobStatus::Retrying, $job->refresh()->status);
        $this->assertSame(['reason' => 'timeout'], $job->context);
        $this->assertSame(ImportBatchStatus::Quarantined, $batch->refresh()->status);
        $this->assertSame(NormalizationStatus::Partial, $offer->refresh()->normalization_status);
        $this->assertSame('10.50', $offer->price);
        $this->assertSame('0.500', $offer->package_amount);
        $this->assertSame('21.00', $offer->unit_price);
    }
}
