<?php

namespace App\Http\Controllers\Api;

use App\Enums\ScrapeJobStatus;
use App\Http\Controllers\Controller;
use App\Imports\Exceptions\DuplicatePaperImportException;
use App\Imports\ImportPersistencePipeline;
use App\Models\ScrapeJob;
use App\Models\ScraperAgent;
use App\Scrapers\DTO\RawPaperPayload;
use App\Scrapers\PaperExistenceChecker;
use App\Scrapers\ScrapeJobWorker;
use App\Scrapers\ScraperRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ScraperAgentController extends Controller
{
    public function version(): JsonResponse
    {
        $desiredVersion = $this->desiredVersion();

        return response()->json([
            'desired_version' => $desiredVersion,
            'compatible' => true,
        ]);
    }

    public function heartbeat(Request $request, ScrapeJobWorker $worker): JsonResponse
    {
        $validated = $request->validate([
            'app_version' => ['nullable', 'string', 'max:255'],
        ]);

        $agent = $this->agent($request);
        $worker->heartbeat($agent->slug, $validated['app_version'] ?? null);

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function updateStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:current,updated,pull_failed'],
            'current_version' => ['nullable', 'string', 'max:255'],
            'desired_version' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $agent = $this->agent($request);
        $metadata = $agent->metadata ?? [];
        $metadata['latest_update_status'] = [
            'status' => $validated['status'],
            'current_version' => $validated['current_version'] ?? null,
            'desired_version' => $validated['desired_version'] ?? null,
            'message' => $validated['message'] ?? null,
            'reported_at' => now()->toIso8601String(),
        ];

        $agent->update([
            'metadata' => $metadata,
        ]);

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function knownPapers(Request $request, PaperExistenceChecker $paperExistenceChecker): JsonResponse
    {
        $validated = $request->validate([
            'grocer' => ['required', 'string', 'max:255'],
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['required', 'string', 'max:255'],
        ]);

        return response()->json([
            'ids' => $paperExistenceChecker->check($validated['grocer'], $validated['ids']),
        ]);
    }

    public function claimJob(Request $request, ScrapeJobWorker $worker): JsonResponse
    {
        $agent = $this->agent($request);

        if ($agent->app_version !== $this->desiredVersion()) {
            return response()->json([
                'message' => 'Scraper agent version is not compatible with the server.',
                'desired_version' => $this->desiredVersion(),
                'current_version' => $agent->app_version,
            ], 409);
        }

        $job = $worker->claimJob($agent);

        if (! $job) {
            return response()->json([
                'job' => null,
            ]);
        }

        return response()->json([
            'job' => [
                'id' => $job->id,
                'grocer' => $job->grocer->slug,
                'attempt' => $job->attempt,
                'leased_until' => $job->leased_until?->toIso8601String(),
            ],
        ]);
    }

    public function storeRawPayloads(Request $request, ScrapeJob $scrapeJob, ScraperRunService $scraperRunService, ImportPersistencePipeline $pipeline, ScrapeJobWorker $worker): JsonResponse
    {
        $this->authorizeJob($request, $scrapeJob);

        $validated = $request->validate([
            'payloads' => ['required', 'array', 'min:1'],
            'payloads.*.source_external_id' => ['required', 'string', 'max:255'],
            'payloads.*.title' => ['nullable', 'string', 'max:255'],
            'payloads.*.raw_payload' => ['required', 'string'],
            'payloads.*.already_fetched' => ['sometimes', 'boolean'],
        ]);

        $scraper = $scraperRunService->scraperFor($scrapeJob->grocer->slug);
        $importedCount = 0;
        $skippedDuplicateCount = 0;

        try {
            foreach ($validated['payloads'] as $payload) {
                if (($payload['already_fetched'] ?? false) === true) {
                    $skippedDuplicateCount++;

                    continue;
                }

                try {
                    $pipeline->persist(
                        $scrapeJob->grocer,
                        $scraper->parse(new RawPaperPayload(
                            sourceExternalId: $payload['source_external_id'],
                            rawPayload: $payload['raw_payload'],
                            title: $payload['title'] ?? null,
                        )),
                        $scrapeJob,
                    );

                    $importedCount++;
                } catch (DuplicatePaperImportException) {
                    $skippedDuplicateCount++;
                }
            }

            $status = $importedCount > 0 ? ScrapeJobStatus::Succeeded : ScrapeJobStatus::NoChanges;
            $worker->markSuccessful($scrapeJob, $status, [
                'fetched_paper_count' => count($validated['payloads']),
                'imported_paper_count' => $importedCount,
                'skipped_duplicate_count' => $skippedDuplicateCount,
            ]);

            return response()->json([
                'status' => $status->value,
                'imported_paper_count' => $importedCount,
                'skipped_duplicate_count' => $skippedDuplicateCount,
            ]);
        } catch (Throwable $exception) {
            $worker->markFailedAttempt($scrapeJob, $exception);

            return response()->json([
                'status' => $scrapeJob->refresh()->status->value,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function failJob(Request $request, ScrapeJob $scrapeJob, ScrapeJobWorker $worker): JsonResponse
    {
        $this->authorizeJob($request, $scrapeJob);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $worker->markFailed($scrapeJob, $validated['message']);

        return response()->json([
            'status' => $scrapeJob->refresh()->status->value,
        ]);
    }

    private function agent(Request $request): ScraperAgent
    {
        /** @var ScraperAgent $agent */
        $agent = $request->attributes->get('scraper_agent');

        return $agent;
    }

    private function authorizeJob(Request $request, ScrapeJob $scrapeJob): void
    {
        $agent = $this->agent($request);

        if ($scrapeJob->scraper_agent_id !== $agent->id || $scrapeJob->status !== ScrapeJobStatus::Running) {
            abort(403, 'Scrape job is not leased to this agent.');
        }
    }

    private function desiredVersion(): string
    {
        return (string) config('app.scraper_agent_version', 'local');
    }
}
