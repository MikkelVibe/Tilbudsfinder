<?php

namespace App\Console\Commands;

use App\Scrapers\ScraperRunService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

#[Signature('scraper-agent:work {--server= : The VPS base URL} {--token= : The scraper agent bearer token} {--app-version= : Current scraper agent image/app version} {--limit= : Limit discovered products/offers for live smoke tests} {--no-delay : Disable scraper politeness delays for tests only}')]
#[Description('Poll the VPS for one scrape job, fetch raw payloads, and upload them')]
class WorkRemoteScraperAgentCommand extends Command
{
    public function handle(ScraperRunService $scraperRunService): int
    {
        $server = rtrim((string) $this->option('server'), '/');
        $token = (string) $this->option('token');
        $version = (string) ($this->option('app-version') ?: config('app.scraper_agent_version', config('app.version', 'local')));

        if ($server === '' || $token === '') {
            $this->error('The --server and --token options are required.');

            return self::FAILURE;
        }

        $client = Http::withToken($token)
            ->acceptJson()
            ->timeout(120)
            ->connectTimeout(10);

        $versionResponse = $client->get($server.'/api/scraper-agent/version')->throw()->json();
        $desiredVersion = (string) data_get($versionResponse, 'desired_version');

        if ($desiredVersion !== '' && $desiredVersion !== $version) {
            $this->warn("Scraper agent version [{$version}] is stale. Desired version is [{$desiredVersion}].");

            return self::SUCCESS;
        }

        $client->post($server.'/api/scraper-agent/heartbeat', [
            'app_version' => $version,
        ])->throw();

        $claim = $client->post($server.'/api/scraper-agent/jobs/claim')->throw()->json('job');

        if (! is_array($claim)) {
            $this->line('No remote scrape jobs are available.');

            return self::SUCCESS;
        }

        $grocerKey = (string) $claim['grocer'];
        $jobId = (string) $claim['id'];
        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');

        $this->line("Claimed remote scrape job {$jobId} for {$grocerKey}.");

        try {
            $scraper = $scraperRunService->scraperFor($grocerKey, ! (bool) $this->option('no-delay'));
            $payloads = $scraper->fetchPapers($limit > 0 ? $limit : null, function (string $message): void {
                $this->line($message);
            });

            $response = $client->post($server."/api/scraper-agent/jobs/{$jobId}/raw-payloads", [
                'payloads' => array_map(fn ($payload): array => [
                    'source_external_id' => $payload->sourceExternalId,
                    'title' => $payload->title,
                    'raw_payload' => $payload->rawPayload,
                ], $payloads),
            ])->throw()->json();
        } catch (Throwable $exception) {
            $client->post($server."/api/scraper-agent/jobs/{$jobId}/fail", [
                'message' => $exception->getMessage(),
            ])->throw();

            $this->error("Remote scrape job {$jobId} failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $status = (string) data_get($response, 'status');
        $this->info("Remote scrape job {$jobId} finished with status {$status}.");

        if (Str::of($status)->is('failed')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
