<?php

namespace App\Scrapers\Dagrofa;

use App\Imports\DTO\ParsedPaperInput;
use App\Scrapers\DTO\PaperCandidate;
use App\Scrapers\DTO\RawPaperPayload;
use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\GrocerScraper;
use App\Scrapers\Support\KnownPaperPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use JsonException;

class DagrofaScraper implements GrocerScraper
{
    private const PAGE_SIZE = 10000;

    public function __construct(
        private readonly DagrofaChain $chain,
        private readonly DagrofaPaperParser $parser = new DagrofaPaperParser,
    ) {}

    public function grocerKey(): string
    {
        return $this->chain->key;
    }

    /**
     * @return list<RawPaperPayload>
     */
    public function discoverPapers(?callable $progress = null): array
    {
        $metadata = $this->fetchIpaperMetadata();

        if (! isset($metadata['paper_uuid'], $metadata['active_from'], $metadata['active_until'])) {
            throw new ScraperFetchException("{$this->chain->name} iPaper metadata did not contain a stable paper UUID and validity dates.");
        }

        return [new PaperCandidate(
            sourceExternalId: $metadata['paper_uuid'],
            title: $metadata['title'] ?? $this->chain->name.' aktuelle tilbud',
            sourcePayload: [
                'source_external_id' => $metadata['paper_uuid'],
                'label' => $metadata['title'] ?? $this->chain->name.' aktuelle tilbud',
                'run_from' => $metadata['active_from'],
                'run_till' => $metadata['active_until'],
                'source_url' => $metadata['source_url'] ?? $this->chain->sourceUrl,
                'ipaper_paper_id' => $metadata['paper_id'] ?? null,
            ],
        )];
    }

    /**
     * @param  list<PaperCandidate>  $candidates
     * @param  array<string, array{exists: bool, title?: ?string, active_from?: ?string, active_until?: ?string}>  $knownPapers
     * @return list<RawPaperPayload>
     */
    public function fetchPapers(array $candidates, array $knownPapers = [], ?int $limit = null, ?callable $progress = null): array
    {
        $candidate = $candidates[0] ?? null;

        if (! $candidate) {
            return [];
        }

        $knownPaper = $knownPapers[$candidate->sourceExternalId] ?? null;

        if (($knownPaper['exists'] ?? false) === true) {
            $this->progress($progress, "Skipping already imported {$this->chain->name} paper {$candidate->sourceExternalId}.");

            return [KnownPaperPayload::make($this->grocerKey(), $candidate, $knownPaper)];
        }

        $this->progress($progress, "Fetching {$this->chain->name} Dagrofa product query...");

        $products = $this->fetchDiscountProducts($limit);

        if ($products === []) {
            throw new ScraperFetchException("{$this->chain->name} returned no discounted products.");
        }

        $this->progress($progress, 'Fetched '.count($products)." {$this->chain->name} discounted products".($limit ? ' after limit' : '').'.');

        return [$this->rawPayload($candidate, $products)];
    }

    public function parse(RawPaperPayload $payload): ParsedPaperInput
    {
        return $this->parser->parse($payload->rawPayload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchDiscountProducts(?int $limit): array
    {
        $response = $this->http()
            ->get($this->chain->baseUrl.'/Product/query', [
                'merchantId' => $this->chain->merchantId,
                'pageNumber' => 0,
                'pageSize' => self::PAGE_SIZE,
                'displayedInStore' => 'true',
            ])
            ->throw()
            ->json();

        $products = Arr::get($response, 'products');

        if (! is_array($products)) {
            throw new ScraperFetchException("{$this->chain->name} product query response did not contain products.");
        }

        $discountProducts = array_values(array_filter(array_filter($products, 'is_array'), fn (array $product): bool => $this->isPublishableDiscount($product)));

        return $limit === null ? $discountProducts : array_slice($discountProducts, 0, $limit);
    }

    /**
     * @param  array<string, mixed>  $product
     */
    private function isPublishableDiscount(array $product): bool
    {
        return is_numeric(Arr::get($product, 'discountPrice'))
            && (float) Arr::get($product, 'discountPrice') > 0
            && (int) Arr::get($product, 'discountAmount', 1) === 1
            && $this->optionalString($product, 'productDisplayName') !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    private function rawPayload(PaperCandidate $candidate, array $products): RawPaperPayload
    {
        $rawPayload = $this->encode([
            'catalog' => [
                'id' => $candidate->sourceExternalId,
                'label' => $candidate->sourcePayload['label'],
                'run_from' => $candidate->sourcePayload['run_from'],
                'run_till' => $candidate->sourcePayload['run_till'],
                'dealer_id' => (string) $this->chain->merchantId,
                'dealer' => ['name' => $this->chain->name],
                'source_url' => $candidate->sourcePayload['source_url'] ?? $this->chain->sourceUrl,
                'source_strategy' => 'dagrofa_longjohn_discount_products',
                'ipaper_paper_id' => $candidate->sourcePayload['ipaper_paper_id'] ?? null,
                'fetched_offer_count' => count($products),
            ],
            'offers' => $products,
        ]);

        return new RawPaperPayload(
            sourceExternalId: $candidate->sourceExternalId,
            rawPayload: $rawPayload,
            title: $candidate->title,
        );
    }

    /**
     * @return array{paper_uuid?: string, title?: string, source_url?: string, paper_id?: int, active_from?: string, active_until?: string}
     */
    private function fetchIpaperMetadata(): array
    {
        if ($this->chain->iPaperUrl === null) {
            return [];
        }

        try {
            $body = $this->http()
                ->get($this->chain->iPaperUrl)
                ->throw()
                ->body();
        } catch (ConnectionException|RequestException) {
            return [];
        }

        if (preg_match('/iPaper\/Papers\/(?<uuid>[0-9a-f-]{36})\//i', $body, $matches) !== 1) {
            return [];
        }

        $settings = $this->ipaperSettingsFragment($body, $matches['uuid']);
        $title = $this->extractIpaperString($settings, 'name') ?? $this->extractIpaperString($settings, 'pageTitle');
        $activePeriod = $this->activePeriodFromText($body);

        return array_filter([
            'paper_uuid' => strtolower($matches['uuid']),
            'title' => $title,
            'source_url' => $this->chain->iPaperUrl,
            'paper_id' => $this->extractIpaperInt($settings, 'paperId'),
            'active_from' => $activePeriod['active_from'] ?? null,
            'active_until' => $activePeriod['active_until'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function ipaperSettingsFragment(string $body, string $paperUuid): string
    {
        $position = strpos($body, $paperUuid);

        if ($position === false) {
            return $body;
        }

        return substr($body, max(0, $position - 2000), 4000);
    }

    /**
     * @return array{active_from?: string, active_until?: string}
     */
    private function activePeriodFromText(string $body): array
    {
        if (preg_match('/g(?:æ|ae)lder.{0,300}?(?<from>\d{1,2}\.\d{1,2}\.\d{4}).{0,300}?(?<until>\d{1,2}\.\d{1,2}\.\d{4})/isu', $body, $matches) === 1) {
            return $this->activePeriodFromDates(
                $this->parseNumericIpaperDate($matches['from']),
                $this->parseNumericIpaperDate($matches['until']),
            );
        }

        if (preg_match('/g(?:æ|ae)lder.{0,300}?(?<from_day>\d{1,2})\.\s*(?<from_month>[[:alpha:]æøåÆØÅ]+)(?:\s+(?<from_year>\d{4}))?.{0,300}?(?<until_day>\d{1,2})\.\s*(?<until_month>[[:alpha:]æøåÆØÅ]+)\s+(?<until_year>\d{4})/isu', $body, $matches) !== 1) {
            return [];
        }

        $untilYear = (int) $matches['until_year'];

        return $this->activePeriodFromDates(
            $this->parseTextualIpaperDate((int) $matches['from_day'], $matches['from_month'], isset($matches['from_year']) && $matches['from_year'] !== '' ? (int) $matches['from_year'] : $untilYear),
            $this->parseTextualIpaperDate((int) $matches['until_day'], $matches['until_month'], $untilYear),
        );
    }

    private function activePeriodFromDates(?CarbonImmutable $from, ?CarbonImmutable $until): array
    {
        $activeFrom = $from?->startOfDay();
        $activeUntil = $until?->endOfDay();

        if ($activeFrom === null || $activeUntil === null || $activeUntil->lessThan($activeFrom)) {
            return [];
        }

        return [
            'active_from' => $activeFrom->toIso8601String(),
            'active_until' => $activeUntil->toIso8601String(),
        ];
    }

    private function parseNumericIpaperDate(string $date): ?CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!d.m.Y', $date);
        $errors = CarbonImmutable::getLastErrors();

        if ($parsed === false
            || (($errors['warning_count'] ?? 0) > 0)
            || (($errors['error_count'] ?? 0) > 0)) {
            return null;
        }

        return $parsed;
    }

    private function parseTextualIpaperDate(int $day, string $month, int $year): ?CarbonImmutable
    {
        $monthNumber = $this->danishMonthNumber($month);

        if ($monthNumber === null) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-n-j', "{$year}-{$monthNumber}-{$day}");
        $errors = CarbonImmutable::getLastErrors();

        if ($parsed === false
            || (($errors['warning_count'] ?? 0) > 0)
            || (($errors['error_count'] ?? 0) > 0)) {
            return null;
        }

        return $parsed;
    }

    private function danishMonthNumber(string $month): ?int
    {
        return [
            'januar' => 1,
            'jan' => 1,
            'februar' => 2,
            'feb' => 2,
            'marts' => 3,
            'mar' => 3,
            'april' => 4,
            'apr' => 4,
            'maj' => 5,
            'juni' => 6,
            'jun' => 6,
            'juli' => 7,
            'jul' => 7,
            'august' => 8,
            'aug' => 8,
            'september' => 9,
            'sep' => 9,
            'oktober' => 10,
            'okt' => 10,
            'november' => 11,
            'nov' => 11,
            'december' => 12,
            'dec' => 12,
        ][mb_strtolower(trim($month), 'UTF-8')] ?? null;
    }

    private function extractIpaperString(string $body, string $key): ?string
    {
        if (preg_match('/["\']?'.preg_quote($key, '/').'["\']?\s*:\s*["\'](?<value>[^"\']+)["\']/i', $body, $matches) !== 1) {
            return null;
        }

        return trim($matches['value']) ?: null;
    }

    private function extractIpaperInt(string $body, string $key): ?int
    {
        if (preg_match('/["\']?'.preg_quote($key, '/').'["\']?\s*:\s*(?<value>\d+)/i', $body, $matches) !== 1) {
            return null;
        }

        return (int) $matches['value'];
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(30)
            ->connectTimeout(5)
            ->retry([200, 500])
            ->withUserAgent('Mozilla/5.0 (compatible; Tilbudsfinder/2.0)');
    }

    private function progress(?callable $progress, string $message): void
    {
        if ($progress !== null) {
            $progress($message);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ScraperFetchException("{$this->chain->name} payload could not be encoded.", previous: $exception);
        }
    }
}
