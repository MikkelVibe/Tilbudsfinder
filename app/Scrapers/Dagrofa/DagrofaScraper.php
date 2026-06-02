<?php

namespace App\Scrapers\Dagrofa;

use App\Imports\DTO\ParsedPaperInput;
use App\Scrapers\DTO\PaperCandidate;
use App\Scrapers\DTO\RawPaperPayload;
use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\GrocerScraper;
use App\Scrapers\Support\KnownPaperPayload;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
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
        $now = CarbonImmutable::now();
        $sourceExternalId = $this->chain->key.'-'.$now->format('Y-m-d');

        return [new PaperCandidate(
            sourceExternalId: $sourceExternalId,
            title: $this->chain->name.' aktuelle tilbud',
            sourcePayload: [
                'source_external_id' => $sourceExternalId,
                'label' => $this->chain->name.' aktuelle tilbud '.$now->format('Y-m-d'),
                'run_from' => $now->startOfDay()->toIso8601String(),
                'run_till' => $now->endOfDay()->toIso8601String(),
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
                'source_url' => $this->chain->sourceUrl,
                'source_strategy' => 'dagrofa_longjohn_discount_products',
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
