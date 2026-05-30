<?php

namespace Tests\Feature\Scrapers;

use App\Enums\GrocerHealthStatus;
use App\Enums\ScrapeJobStatus;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\ScrapeJob;
use App\Scrapers\DTO\ScraperRunResult;
use App\Scrapers\Exceptions\ScraperRunException;
use App\Scrapers\ScraperRunService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ScrapeAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_schedules_due_enabled_grocers_once(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 03:00:00');
        $dueGrocer = Grocer::factory()->create([
            'slug' => 'rema1000',
            'next_expected_import_at' => now()->subMinute(),
        ]);
        Grocer::factory()->create([
            'slug' => 'future',
            'next_expected_import_at' => now()->addHour(),
        ]);
        Grocer::factory()->create([
            'slug' => 'disabled',
            'is_enabled' => false,
            'next_expected_import_at' => now()->subMinute(),
        ]);
        ScrapeJob::factory()->for($dueGrocer)->create([
            'status' => ScrapeJobStatus::Pending,
            'scheduled_for' => now()->subMinute(),
        ]);

        $this->artisan('scraper:schedule')
            ->expectsOutput('Scheduled scrape jobs: 0')
            ->assertSuccessful();

        $this->assertSame(1, ScrapeJob::query()->count());

        ScrapeJob::query()->delete();

        $this->artisan('scraper:schedule')
            ->expectsOutput('Scheduled scrape jobs: 1')
            ->assertSuccessful();

        $this->assertSame(1, ScrapeJob::query()->count());
        $this->assertTrue(ScrapeJob::query()->firstOrFail()->grocer->is($dueGrocer));
    }

    public function test_scheduler_recovers_expired_running_job_leases(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $grocer = Grocer::factory()->create([
            'slug' => 'netto',
            'next_expected_import_at' => now()->subMinute(),
        ]);
        $job = ScrapeJob::factory()->for($grocer)->create([
            'status' => ScrapeJobStatus::Running,
            'attempt' => 1,
            'scheduled_for' => now()->subHours(4),
            'leased_until' => now()->subMinute(),
        ]);

        $this->artisan('scraper:schedule')
            ->expectsOutput('Scheduled scrape jobs: 0')
            ->assertSuccessful();

        $job->refresh();
        $grocer->refresh();

        $this->assertSame(ScrapeJobStatus::Retrying, $job->status);
        $this->assertSame('Scrape job lease expired before the agent reported completion.', $job->failure_reason);
        $this->assertSame('2026-06-01 12:30:00', $job->scheduled_for->format('Y-m-d H:i:s'));
        $this->assertSame(GrocerHealthStatus::Failing, $grocer->health_status);
        $this->assertSame(1, ScrapeJob::query()->count());
    }

    public function test_it_requires_agent_slug_for_worker(): void
    {
        $this->artisan('scraper:work')
            ->expectsOutput('The --agent option is required.')
            ->assertFailed();
    }

    public function test_worker_marks_importing_job_as_succeeded(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $job = ScrapeJob::factory()->for($grocer)->create([
            'scraper_agent_id' => null,
            'status' => ScrapeJobStatus::Pending,
            'scheduled_for' => now()->subMinute(),
        ]);

        $this->mockScraperRunService(new ScraperRunResult('rema1000', 1, 1, 0));

        $this->artisan('scraper:work --agent=apartment-laptop')
            ->expectsOutput("Running scrape job {$job->id} for rema1000...")
            ->expectsOutput("Scrape job {$job->id} finished with status succeeded.")
            ->assertSuccessful();

        $job->refresh();
        $grocer->refresh();

        $this->assertSame(ScrapeJobStatus::Succeeded, $job->status);
        $this->assertSame(1, $job->attempt);
        $this->assertNotNull($job->scraper_agent_id);
        $this->assertNotNull($job->payload_received_at);
        $this->assertSame(['fetched_paper_count' => 1, 'imported_paper_count' => 1, 'skipped_duplicate_count' => 0], $job->context);
        $this->assertSame(GrocerHealthStatus::Healthy, $grocer->health_status);
        $this->assertSame('2026-06-02 02:00:00', $grocer->next_expected_import_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('scraper_agents', [
            'slug' => 'apartment-laptop',
        ]);
    }

    public function test_worker_marks_duplicate_only_job_as_no_changes(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $grocer = Grocer::factory()->create(['slug' => 'netto']);
        $job = ScrapeJob::factory()->for($grocer)->create([
            'status' => ScrapeJobStatus::Pending,
            'scheduled_for' => now()->subMinute(),
        ]);

        $this->mockScraperRunService(new ScraperRunResult('netto', 1, 0, 1));

        $this->artisan('scraper:work --agent=apartment-laptop')
            ->expectsOutput("Scrape job {$job->id} finished with status no_changes.")
            ->assertSuccessful();

        $this->assertSame(ScrapeJobStatus::NoChanges, $job->refresh()->status);
        $this->assertSame(GrocerHealthStatus::Healthy, $grocer->refresh()->health_status);
    }

    public function test_worker_retries_failed_job_with_backoff(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $grocer = Grocer::factory()->create(['slug' => 'bilka']);
        $job = ScrapeJob::factory()->for($grocer)->create([
            'status' => ScrapeJobStatus::Pending,
            'scheduled_for' => now()->subMinute(),
        ]);

        $this->mockFailingScraperRunService('temporary failure');

        $this->artisan('scraper:work --agent=apartment-laptop')
            ->expectsOutput("Scrape job {$job->id} failed: temporary failure")
            ->assertSuccessful();

        $job->refresh();
        $grocer->refresh();

        $this->assertSame(ScrapeJobStatus::Retrying, $job->status);
        $this->assertSame(1, $job->attempt);
        $this->assertSame('2026-06-01 12:30:00', $job->scheduled_for->format('Y-m-d H:i:s'));
        $this->assertSame(GrocerHealthStatus::Failing, $grocer->health_status);
        $this->assertSame('2026-06-01 12:30:00', $grocer->next_expected_import_at->format('Y-m-d H:i:s'));
    }

    public function test_worker_marks_final_failure_as_failed_and_stale(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $grocer = Grocer::factory()->create(['slug' => 'foetex']);
        $job = ScrapeJob::factory()->for($grocer)->create([
            'status' => ScrapeJobStatus::Retrying,
            'attempt' => 2,
            'max_attempts' => 3,
            'scheduled_for' => now()->subMinute(),
        ]);

        $this->mockFailingScraperRunService('still broken');

        $this->artisan('scraper:work --agent=apartment-laptop')
            ->expectsOutput("Scrape job {$job->id} failed: still broken")
            ->assertFailed();

        $job->refresh();
        $grocer->refresh();

        $this->assertSame(ScrapeJobStatus::Failed, $job->status);
        $this->assertSame(3, $job->attempt);
        $this->assertSame(GrocerHealthStatus::Stale, $grocer->health_status);
        $this->assertSame('2026-06-02 02:00:00', $grocer->next_expected_import_at->format('Y-m-d H:i:s'));
    }

    public function test_worker_has_no_work_when_no_jobs_are_due(): void
    {
        $this->mockScraperRunService(new ScraperRunResult('netto', 0, 0, 0), shouldRun: false);

        $this->artisan('scraper:work --agent=apartment-laptop')
            ->expectsOutput('No scrape jobs are available.')
            ->assertSuccessful();

        $this->assertSame(0, ImportBatch::query()->count());
        $this->assertDatabaseHas('scraper_agents', [
            'slug' => 'apartment-laptop',
        ]);
    }

    private function mockScraperRunService(ScraperRunResult $result, bool $shouldRun = true): void
    {
        $mock = Mockery::mock(ScraperRunService::class);

        if ($shouldRun) {
            $mock->shouldReceive('run')->once()->andReturn($result);
        } else {
            $mock->shouldNotReceive('run');
        }

        $this->instance(ScraperRunService::class, $mock);
    }

    private function mockFailingScraperRunService(string $message): void
    {
        $mock = Mockery::mock(ScraperRunService::class);
        $mock->shouldReceive('run')->once()->andThrow(new ScraperRunException($message));

        $this->instance(ScraperRunService::class, $mock);
    }
}
