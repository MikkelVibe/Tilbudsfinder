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

    public function test_it_waits_until_two_am_copenhagen_before_scheduling_daily_cycles(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 23:30:00');
        Grocer::factory()->create([
            'slug' => 'rema1000',
        ]);

        $this->artisan('scraper:schedule')
            ->expectsOutput('Scheduled scrape jobs: 0')
            ->assertSuccessful();

        $this->assertSame(0, ScrapeJob::query()->count());
    }

    public function test_it_schedules_one_daily_cycle_per_enabled_grocer_after_two_am_copenhagen(): void
    {
        CarbonImmutable::setTestNow('2026-06-02 00:30:00');
        $rema = Grocer::factory()->create([
            'slug' => 'rema1000',
        ]);
        Grocer::factory()->create([
            'slug' => 'netto',
        ]);
        Grocer::factory()->create([
            'slug' => 'disabled',
            'is_enabled' => false,
        ]);
        ScrapeJob::factory()->for($rema)->create([
            'status' => ScrapeJobStatus::Pending,
            'scrape_date' => '2026-06-02',
            'scheduled_for' => now(),
        ]);

        $this->artisan('scraper:schedule')
            ->expectsOutput('Scheduled scrape jobs: 1')
            ->assertSuccessful();

        $this->assertSame(2, ScrapeJob::query()->count());
        $this->assertDatabaseHas('scrape_jobs', [
            'scrape_date' => '2026-06-02',
        ]);
        $this->assertTrue(ScrapeJob::query()->whereRelation('grocer', 'slug', 'netto')->exists());

        $this->artisan('scraper:schedule')
            ->expectsOutput('Scheduled scrape jobs: 0')
            ->assertSuccessful();

        $this->assertSame(2, ScrapeJob::query()->count());
    }

    public function test_scheduler_fails_expired_daily_cycles_before_scheduling_today(): void
    {
        CarbonImmutable::setTestNow('2026-06-02 00:30:00');
        $grocer = Grocer::factory()->create([
            'slug' => 'rema1000',
        ]);
        $expiredJob = ScrapeJob::factory()->for($grocer)->create([
            'status' => ScrapeJobStatus::Retrying,
            'scrape_date' => '2026-06-01',
            'scheduled_for' => now()->subHours(2),
        ]);

        $this->artisan('scraper:schedule')
            ->expectsOutput('Scheduled scrape jobs: 1')
            ->assertSuccessful();

        $expiredJob->refresh();
        $grocer->refresh();

        $this->assertSame(ScrapeJobStatus::Failed, $expiredJob->status);
        $this->assertSame(GrocerHealthStatus::Stale, $grocer->health_status);
        $this->assertSame(2, ScrapeJob::query()->count());
        $this->assertTrue(ScrapeJob::query()->where('scrape_date', '2026-06-02')->exists());
    }

    public function test_scheduler_recovers_expired_running_job_leases(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $grocer = Grocer::factory()->create([
            'slug' => 'netto',
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
    }

    public function test_worker_marks_failure_as_failed_and_stale_when_retry_would_cross_midnight(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 21:30:00');
        $grocer = Grocer::factory()->create(['slug' => 'foetex']);
        $job = ScrapeJob::factory()->for($grocer)->create([
            'status' => ScrapeJobStatus::Retrying,
            'attempt' => 5,
            'scrape_date' => '2026-06-01',
            'scheduled_for' => now()->subMinute(),
        ]);

        $this->mockFailingScraperRunService('still broken');

        $this->artisan('scraper:work --agent=apartment-laptop')
            ->expectsOutput("Scrape job {$job->id} failed: still broken")
            ->assertFailed();

        $job->refresh();
        $grocer->refresh();

        $this->assertSame(ScrapeJobStatus::Failed, $job->status);
        $this->assertSame(6, $job->attempt);
        $this->assertSame(GrocerHealthStatus::Stale, $grocer->health_status);
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
