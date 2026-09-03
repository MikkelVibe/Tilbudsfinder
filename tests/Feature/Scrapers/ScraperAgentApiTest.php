<?php

namespace Tests\Feature\Scrapers;

use App\Enums\GrocerHealthStatus;
use App\Enums\ImportBatchStatus;
use App\Enums\ScrapeJobStatus;
use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Models\ScrapeJob;
use App\Models\ScraperAgent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScraperAgentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_command_issues_hashed_agent_token(): void
    {
        $this->artisan('scraper-agent:token apartment-laptop')
            ->expectsOutputToContain('Token issued for scraper agent [apartment-laptop].')
            ->assertSuccessful();

        $agent = ScraperAgent::query()->where('slug', 'apartment-laptop')->firstOrFail();

        $this->assertNotNull($agent->token_hash);
        $this->assertSame(64, strlen((string) $agent->token_hash));
    }

    public function test_agent_api_requires_valid_bearer_token(): void
    {
        $this->getJson('/api/scraper-agent/version')->assertUnauthorized();

        $this->withToken('wrong-token')
            ->getJson('/api/scraper-agent/version')
            ->assertUnauthorized();
    }

    public function test_agent_can_heartbeat_and_claim_one_job(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        config(['app.scraper_agent_version' => 'sha-123']);
        $token = 'secret-token';
        $agent = $this->agent($token);
        $grocer = Grocer::factory()->create(['slug' => 'netto']);
        $job = ScrapeJob::factory()->for($grocer)->create([
            'scraper_agent_id' => null,
            'status' => ScrapeJobStatus::Pending,
            'scheduled_for' => now()->subMinute(),
        ]);

        $this->withToken($token)
            ->postJson('/api/scraper-agent/heartbeat', ['app_version' => 'sha-123'])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertSame('sha-123', $agent->refresh()->app_version);

        $this->withToken($token)
            ->postJson('/api/scraper-agent/jobs/claim')
            ->assertOk()
            ->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('job.grocer', 'netto')
            ->assertJsonPath('job.attempt', 1);

        $job->refresh();

        $this->assertSame(ScrapeJobStatus::Running, $job->status);
        $this->assertSame($agent->id, $job->scraper_agent_id);
    }

    public function test_agent_cannot_claim_job_with_stale_version(): void
    {
        config(['app.scraper_agent_version' => 'sha-new']);
        $token = 'secret-token';
        $this->agent($token, ['app_version' => 'sha-old']);

        $this->withToken($token)
            ->postJson('/api/scraper-agent/jobs/claim')
            ->assertConflict()
            ->assertJsonPath('desired_version', 'sha-new')
            ->assertJsonPath('current_version', 'sha-old');
    }

    public function test_agent_can_report_update_status_without_changing_health(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $token = 'secret-token';
        $agent = $this->agent($token, [
            'metadata' => ['host' => 'laptop'],
        ]);

        $this->withToken($token)
            ->postJson('/api/scraper-agent/update-status', [
                'status' => 'pull_failed',
                'current_version' => 'sha-old',
                'desired_version' => 'sha-new',
                'message' => 'Image pull failed.',
            ])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $agent->refresh();

        $this->assertSame('active', $agent->status->value);
        $this->assertSame('laptop', $agent->metadata['host']);
        $this->assertSame([
            'status' => 'pull_failed',
            'current_version' => 'sha-old',
            'desired_version' => 'sha-new',
            'message' => 'Image pull failed.',
            'reported_at' => '2026-06-01T12:00:00+00:00',
        ], $agent->metadata['latest_update_status']);
    }

    public function test_agent_can_check_known_paper_ids_for_a_grocer(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $token = 'secret-token';
        $this->agent($token);
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $batch = ImportBatch::factory()->for($grocer)->create();
        Paper::factory()->for($grocer)->for($batch, 'importBatch')->create([
            'source_external_id' => 'weekly-paper',
            'title' => 'Uge 23',
            'active_from' => '2026-06-01 00:00:00',
            'active_until' => '2026-06-07 23:59:59',
        ]);

        $this->withToken($token)
            ->postJson('/api/scraper-agent/papers/exists', [
                'grocer' => 'rema1000',
                'ids' => ['weekly-paper', 'insert-paper'],
            ])
            ->assertOk()
            ->assertJsonPath('ids.weekly-paper.exists', true)
            ->assertJsonPath('ids.weekly-paper.title', 'Uge 23')
            ->assertJsonPath('ids.insert-paper.exists', false);
    }

    public function test_agent_can_upload_raw_payloads_for_synchronous_ingest(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $token = 'secret-token';
        $agent = $this->agent($token);
        $grocer = Grocer::factory()->create(['slug' => 'netto']);
        $job = ScrapeJob::factory()->for($grocer)->for($agent, 'scraperAgent')->create([
            'status' => ScrapeJobStatus::Running,
            'attempt' => 1,
            'scheduled_for' => now()->subMinute(),
            'leased_until' => now()->addHours(3),
        ]);

        $this->withToken($token)
            ->postJson("/api/scraper-agent/jobs/{$job->id}/raw-payloads", [
                'attempt' => 1,
                'payloads' => [[
                    'source_external_id' => 'weekly-paper',
                    'title' => 'Uge 23',
                    'raw_payload' => json_encode($this->nettoPaperPayload(), JSON_THROW_ON_ERROR),
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'succeeded')
            ->assertJsonPath('imported_paper_count', 1)
            ->assertJsonPath('skipped_duplicate_count', 0);

        $job->refresh();
        $grocer->refresh();

        $this->assertSame(ScrapeJobStatus::Succeeded, $job->status);
        $this->assertSame(['fetched_paper_count' => 1, 'imported_paper_count' => 1, 'skipped_duplicate_count' => 0], $job->context);
        $this->assertSame(GrocerHealthStatus::Healthy, $grocer->health_status);
        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(1, Paper::query()->count());
    }

    public function test_agent_upload_imports_later_payloads_before_retrying_a_failed_paper(): void
    {
        CarbonImmutable::setTestNow('2026-09-03 12:00:00');
        Storage::fake('local');
        Queue::fake();
        $token = 'secret-token';
        $agent = $this->agent($token);
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $job = ScrapeJob::factory()->for($grocer)->for($agent, 'scraperAgent')->create([
            'status' => ScrapeJobStatus::Running,
            'attempt' => 1,
            'scheduled_for' => now()->subMinute(),
            'leased_until' => now()->addHours(3),
        ]);
        $unmatchedPayload = $this->remaFixturePayload('paper-unmatched');
        $unmatchedPayload['offers'] = [];
        $unmatchedPayload['catalog']['matched_tjek_offer_count'] = 0;
        $unmatchedPayload['catalog']['matched_product_count'] = 0;
        $unmatchedPayload['catalog']['missing_tjek_offer_count'] = 2;
        $validPayload = $this->remaFixturePayload('paper-valid');

        $this->withToken($token)
            ->postJson("/api/scraper-agent/jobs/{$job->id}/raw-payloads", [
                'attempt' => 1,
                'payloads' => [
                    [
                        'source_external_id' => 'paper-unmatched',
                        'title' => 'Uge 36',
                        'raw_payload' => json_encode($unmatchedPayload, JSON_THROW_ON_ERROR),
                    ],
                    [
                        'source_external_id' => 'paper-valid',
                        'title' => 'Uge 36 Indstik',
                        'raw_payload' => json_encode($validPayload, JSON_THROW_ON_ERROR),
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'retrying')
            ->assertJsonPath('message', 'Paper paper-unmatched failed: Import produced zero publishable offers.');

        $this->assertSame(ScrapeJobStatus::Retrying, $job->refresh()->status);
        $this->assertSame(GrocerHealthStatus::Failing, $grocer->refresh()->health_status);
        $this->assertSame([
            'fetched_paper_count' => 2,
            'imported_paper_count' => 1,
            'skipped_duplicate_count' => 0,
            'last_failed_attempt' => 1,
        ], $job->context);
        $this->assertSame(ImportBatchStatus::Failed, ImportBatch::query()->where('source_external_id', 'paper-unmatched')->value('status'));
        $successfulBatch = ImportBatch::query()->where('source_external_id', 'paper-valid')->firstOrFail();

        $this->assertSame(ImportBatchStatus::Succeeded, $successfulBatch->status);
        $this->assertSame(1, ScrapedOffer::query()->where('import_batch_id', $successfulBatch->id)->count());
    }

    public function test_agent_can_upload_already_fetched_proof_without_importing(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $token = 'secret-token';
        $agent = $this->agent($token);
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $job = ScrapeJob::factory()->for($grocer)->for($agent, 'scraperAgent')->create([
            'status' => ScrapeJobStatus::Running,
            'attempt' => 1,
            'scheduled_for' => now()->subMinute(),
            'leased_until' => now()->addHours(3),
        ]);

        $this->withToken($token)
            ->postJson("/api/scraper-agent/jobs/{$job->id}/raw-payloads", [
                'attempt' => 1,
                'payloads' => [[
                    'source_external_id' => 'weekly-paper',
                    'title' => 'Uge 23',
                    'raw_payload' => json_encode(['status' => 'already_fetched'], JSON_THROW_ON_ERROR),
                    'already_fetched' => true,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'no_changes')
            ->assertJsonPath('imported_paper_count', 0)
            ->assertJsonPath('skipped_duplicate_count', 1);

        $this->assertSame(0, ImportBatch::query()->count());
        $this->assertSame(ScrapeJobStatus::NoChanges, $job->refresh()->status);
    }

    public function test_agent_can_report_claimed_job_failure(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $token = 'secret-token';
        $agent = $this->agent($token);
        $grocer = Grocer::factory()->create(['slug' => 'netto']);
        $job = ScrapeJob::factory()->for($grocer)->for($agent, 'scraperAgent')->create([
            'status' => ScrapeJobStatus::Running,
            'attempt' => 1,
            'scheduled_for' => now()->subMinute(),
            'leased_until' => now()->addHours(3),
        ]);

        $this->withToken($token)
            ->postJson("/api/scraper-agent/jobs/{$job->id}/fail", [
                'attempt' => 1,
                'message' => 'Laptop fetch failed.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'retrying');

        $job->refresh();

        $this->assertSame(ScrapeJobStatus::Retrying, $job->status);
        $this->assertSame('Laptop fetch failed.', $job->failure_reason);
        $this->assertSame('2026-06-01 12:30:00', $job->scheduled_for->format('Y-m-d H:i:s'));
        $this->assertSame(GrocerHealthStatus::Failing, $grocer->refresh()->health_status);
    }

    public function test_agent_cannot_upload_or_fail_a_newer_job_attempt(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $token = 'secret-token';
        $agent = $this->agent($token);
        $grocer = Grocer::factory()->create(['slug' => 'netto']);
        $job = ScrapeJob::factory()->for($grocer)->for($agent, 'scraperAgent')->create([
            'status' => ScrapeJobStatus::Running,
            'attempt' => 2,
            'scheduled_for' => now()->subMinute(),
            'leased_until' => now()->addHours(3),
        ]);

        $this->withToken($token)
            ->postJson("/api/scraper-agent/jobs/{$job->id}/raw-payloads", [
                'attempt' => 1,
                'payloads' => [[
                    'source_external_id' => 'stale-paper',
                    'title' => 'Stale paper',
                    'raw_payload' => json_encode($this->nettoPaperPayload(), JSON_THROW_ON_ERROR),
                ]],
            ])
            ->assertConflict()
            ->assertJsonPath('status', 'stale_attempt');

        $this->withToken($token)
            ->postJson("/api/scraper-agent/jobs/{$job->id}/fail", [
                'attempt' => 1,
                'message' => 'Late failure from attempt one.',
            ])
            ->assertConflict()
            ->assertJsonPath('status', 'stale_attempt');

        $otherToken = 'other-secret-token';
        ScraperAgent::factory()->create([
            'slug' => 'other-agent',
            'token_hash' => hash('sha256', $otherToken),
        ]);

        $this->withToken($otherToken)
            ->postJson("/api/scraper-agent/jobs/{$job->id}/fail", [
                'attempt' => 2,
                'message' => 'Failure from the wrong agent.',
            ])
            ->assertConflict()
            ->assertJsonPath('status', 'stale_attempt');

        $job->refresh();

        $this->assertSame(ScrapeJobStatus::Running, $job->status);
        $this->assertSame(2, $job->attempt);
        $this->assertNull($job->failure_reason);
        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_agent_cannot_upload_after_its_lease_expires(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        $token = 'secret-token';
        $agent = $this->agent($token);
        $grocer = Grocer::factory()->create(['slug' => 'netto']);
        $job = ScrapeJob::factory()->for($grocer)->for($agent, 'scraperAgent')->create([
            'status' => ScrapeJobStatus::Running,
            'attempt' => 1,
            'scheduled_for' => now()->subMinute(),
            'leased_until' => now()->subSecond(),
        ]);

        $this->withToken($token)
            ->postJson("/api/scraper-agent/jobs/{$job->id}/raw-payloads", [
                'attempt' => 1,
                'payloads' => [[
                    'source_external_id' => 'expired-paper',
                    'title' => 'Expired paper',
                    'raw_payload' => json_encode($this->nettoPaperPayload(), JSON_THROW_ON_ERROR),
                ]],
            ])
            ->assertConflict()
            ->assertJsonPath('status', 'stale_attempt');

        $this->assertSame(ScrapeJobStatus::Running, $job->refresh()->status);
        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_agent_command_claims_fetches_and_uploads_remote_job(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');

        Http::fake([
            '*tilbud.test/api/scraper-agent/version' => Http::response([
                'desired_version' => 'sha-123',
                'compatible' => true,
            ]),
            '*tilbud.test/api/scraper-agent/heartbeat' => Http::response(['status' => 'ok']),
            '*tilbud.test/api/scraper-agent/jobs/claim' => Http::response([
                'job' => [
                    'id' => 'job-123',
                    'grocer' => 'netto',
                    'attempt' => 1,
                    'leased_until' => now()->addHour()->toIso8601String(),
                ],
            ]),
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->nettoCatalog('weekly-paper', 'Uge 23', 12),
            ]),
            '*tilbud.test/api/scraper-agent/papers/exists' => Http::response([
                'ids' => [
                    'weekly-paper' => ['exists' => false],
                ],
            ]),
            'squid-api.tjek.com/v2/offers?catalog_id=weekly-paper&offset=0&limit=100' => Http::response(array_map(fn (int $number): array => $this->nettoOffer($number), range(1, 12))),
            '*tilbud.test/api/scraper-agent/jobs/job-123/raw-payloads' => Http::response([
                'status' => 'succeeded',
                'imported_paper_count' => 1,
                'skipped_duplicate_count' => 0,
            ]),
        ]);

        $this->artisan('scraper-agent:work --server=https://tilbud.test --token=secret --app-version=sha-123')
            ->assertSuccessful();

        Http::assertSentCount(7);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tilbud.test/api/scraper-agent/version');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tilbud.test/api/scraper-agent/heartbeat'
            && $request['app_version'] === 'sha-123');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tilbud.test/api/scraper-agent/jobs/claim');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tilbud.test/api/scraper-agent/papers/exists'
            && $request['grocer'] === 'netto'
            && $request['ids'] === ['weekly-paper']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tilbud.test/api/scraper-agent/jobs/job-123/raw-payloads'
            && $request['attempt'] === 1
            && count($request['payloads']) === 1
            && $request['payloads'][0]['source_external_id'] === 'weekly-paper');
    }

    public function test_agent_command_reports_failure_after_claim(): void
    {
        Http::fake([
            '*tilbud.test/api/scraper-agent/version' => Http::response([
                'desired_version' => 'sha-123',
                'compatible' => true,
            ]),
            '*tilbud.test/api/scraper-agent/heartbeat' => Http::response(['status' => 'ok']),
            '*tilbud.test/api/scraper-agent/jobs/claim' => Http::response([
                'job' => [
                    'id' => 'job-123',
                    'grocer' => 'unsupported-grocer',
                    'attempt' => 1,
                    'leased_until' => now()->addHours(3)->toIso8601String(),
                ],
            ]),
            '*tilbud.test/api/scraper-agent/jobs/job-123/fail' => Http::response([
                'status' => 'retrying',
            ]),
        ]);

        $this->artisan('scraper-agent:work --server=https://tilbud.test --token=secret --app-version=sha-123')
            ->expectsOutputToContain('Remote scrape job job-123 failed: Scraper [unsupported-grocer] is not supported.')
            ->assertFailed();

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tilbud.test/api/scraper-agent/jobs/job-123/fail'
            && $request['attempt'] === 1
            && $request['message'] === 'Scraper [unsupported-grocer] is not supported.');
    }

    public function test_agent_command_does_not_report_a_server_handled_upload_failure_twice(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');

        Http::fake([
            '*tilbud.test/api/scraper-agent/version' => Http::response([
                'desired_version' => 'sha-123',
                'compatible' => true,
            ]),
            '*tilbud.test/api/scraper-agent/heartbeat' => Http::response(['status' => 'ok']),
            '*tilbud.test/api/scraper-agent/jobs/claim' => Http::response([
                'job' => [
                    'id' => 'job-123',
                    'grocer' => 'netto',
                    'attempt' => 1,
                    'leased_until' => now()->addHour()->toIso8601String(),
                ],
            ]),
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->nettoCatalog('weekly-paper', 'Uge 23', 12),
            ]),
            '*tilbud.test/api/scraper-agent/papers/exists' => Http::response([
                'ids' => ['weekly-paper' => ['exists' => false]],
            ]),
            'squid-api.tjek.com/v2/offers?catalog_id=weekly-paper&offset=0&limit=100' => Http::response(array_map(fn (int $number): array => $this->nettoOffer($number), range(1, 12))),
            '*tilbud.test/api/scraper-agent/jobs/job-123/raw-payloads' => Http::response([
                'status' => 'retrying',
                'message' => 'Paper weekly-paper failed validation.',
            ], 422),
            '*tilbud.test/api/scraper-agent/jobs/job-123/fail' => Http::response([
                'status' => 'retrying',
            ]),
        ]);

        $this->artisan('scraper-agent:work --server=https://tilbud.test --token=secret --app-version=sha-123')
            ->assertFailed();

        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://tilbud.test/api/scraper-agent/jobs/job-123/fail');
    }

    public function test_agent_command_exits_before_claim_when_version_is_stale(): void
    {
        Http::fake([
            '*tilbud.test/api/scraper-agent/version' => Http::response([
                'desired_version' => 'sha-new',
                'compatible' => true,
            ]),
        ]);

        $this->artisan('scraper-agent:work --server=https://tilbud.test --token=secret --app-version=sha-old')
            ->expectsOutput('Scraper agent version [sha-old] is stale. Desired version is [sha-new].')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://tilbud.test/api/scraper-agent/version');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function agent(string $token, array $attributes = []): ScraperAgent
    {
        return ScraperAgent::factory()->create([
            'slug' => 'apartment-laptop',
            'token_hash' => hash('sha256', $token),
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function nettoPaperPayload(): array
    {
        return [
            'catalog' => $this->nettoCatalog('weekly-paper', 'Uge 23', 12),
            'offers' => array_map(fn (int $number): array => $this->nettoOffer($number), range(1, 12)),
        ];
    }

    /** @return array<string, mixed> */
    private function remaFixturePayload(string $catalogId): array
    {
        $payload = json_decode(
            file_get_contents(base_path('tests/Fixtures/scrapers/rema1000/uge-36-matched-flow.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $payload['catalog']['id'] = $catalogId;

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function nettoCatalog(string $id, string $label, int $offerCount): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'run_from' => '2026-05-29T22:00:00+0000',
            'run_till' => '2026-06-05T21:59:59+0000',
            'offer_count' => $offerCount,
            'page_count' => 36,
            'dealer_id' => '9ba51',
            'dealer' => ['name' => 'Netto'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nettoOffer(int $number): array
    {
        return [
            'id' => "netto-offer-{$number}",
            'heading' => "Netto Product {$number}",
            'description' => '200 g. Pr. kg 50,00.',
            'catalog_page' => $number,
            'pricing' => ['price' => 10, 'currency' => 'DKK'],
            'quantity' => [
                'unit' => ['symbol' => 'g'],
                'size' => ['from' => 200, 'to' => 200],
                'pieces' => ['from' => 1, 'to' => 1],
            ],
            'images' => ['zoom' => "https://images.example/netto/{$number}.webp"],
            'catalog_id' => 'weekly-paper',
        ];
    }
}
