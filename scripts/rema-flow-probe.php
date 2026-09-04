#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Scrapers\Rema1000\Rema1000Scraper;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$application = require dirname(__DIR__).'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();

$options = getopt('', ['limit::']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : null;

try {
    /** @var Rema1000Scraper $scraper */
    $scraper = $application->make(Rema1000Scraper::class);
    $progress = static function (string $message): void {
        fwrite(STDERR, $message.PHP_EOL);
    };
    $candidates = $scraper->discoverPapers($progress);
    $payloads = $scraper->fetchPapers($candidates, limit: $limit, progress: $progress);
    $papers = array_map($scraper->parse(...), $payloads);

    $report = array_map(static function ($paper): array {
        $fetched = (int) ($paper->metadata['fetched_offer_count'] ?? 0);
        $matched = (int) ($paper->metadata['matched_tjek_offer_count'] ?? 0);

        return [
            'paper_id' => $paper->sourceExternalId,
            'title' => $paper->title,
            'fetched_tjek_offers' => $fetched,
            'matched_tjek_offers' => $matched,
            'matched_rema_products' => count($paper->offers),
            'logged_issues' => count($paper->issues),
            'coverage_percent' => $fetched === 0 ? 0 : round(($matched / $fetched) * 100, 1),
        ];
    }, $papers);

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'REMA flow probe failed: '.$exception->getMessage().PHP_EOL);

    exit(1);
}
