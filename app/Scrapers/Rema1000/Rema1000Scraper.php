<?php

namespace App\Scrapers\Rema1000;

use App\Imports\DTO\ParsedPaperInput;
use App\Scrapers\DTO\PaperCandidate;
use App\Scrapers\DTO\RawPaperPayload;
use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\GrocerScraper;
use JsonException;

class Rema1000Scraper implements GrocerScraper
{
    public function __construct(
        private readonly Rema1000PaperParser $parser = new Rema1000PaperParser,
        private readonly RemaAdvertisedProductClient $productClient = new RemaAdvertisedProductClient,
        private readonly RemaTjekClient $tjekClient = new RemaTjekClient,
        private readonly RemaOfferMatcher $matcher = new RemaOfferMatcher,
    ) {}

    public function grocerKey(): string
    {
        return 'rema1000';
    }

    /** @return list<PaperCandidate> */
    public function discoverPapers(?callable $progress = null): array
    {
        $this->progress($progress, 'Fetching fully paginated REMA 1000 weekly Tjek catalogs...');
        $catalogs = $this->tjekClient->activeWeeklyCatalogs();
        $this->progress($progress, 'Found '.count($catalogs).' active REMA 1000 Uge catalogs eligible for reconciliation.');

        return array_map(fn (array $catalog): PaperCandidate => new PaperCandidate(
            sourceExternalId: $this->requiredString($catalog, 'id'),
            title: $this->optionalString($catalog, 'label'),
            sourcePayload: $catalog,
        ), $catalogs);
    }

    /**
     * REMA papers are fetched even when already known because its product
     * endpoint is a current snapshot and later products need reconciliation.
     *
     * @param  list<PaperCandidate>  $candidates
     * @param  array<string, array{exists: bool, title?: ?string, active_from?: ?string, active_until?: ?string}>  $knownPapers
     * @return list<RawPaperPayload>
     */
    public function fetchPapers(array $candidates, array $knownPapers = [], ?int $limit = null, ?callable $progress = null): array
    {
        if ($candidates === []) {
            return [];
        }

        $this->progress($progress, 'Fetching fully paginated REMA advertised products without Algolia...');
        $products = $this->productClient->fetch($limit);
        $this->progress($progress, 'Found '.count($products).' REMA advertised products.');
        $payloads = [];

        foreach ($candidates as $candidate) {
            $catalog = $candidate->sourcePayload;
            $catalogId = $candidate->sourceExternalId;
            $this->progress($progress, "Fetching every Tjek offer for {$catalogId}...");
            $tjekOffers = $this->tjekClient->offers($catalog);
            $match = $this->matcher->match($catalog, $tjekOffers, $products);

            $payloads[] = new RawPaperPayload(
                sourceExternalId: $catalogId,
                rawPayload: $this->encode([
                    'catalog' => [
                        ...$catalog,
                        'source_strategy' => 'rema_tjek_offer_match',
                        'fetched_offer_count' => count($tjekOffers),
                        'matched_tjek_offer_count' => $match->matchedTjekOfferCount,
                        'matched_product_count' => count($match->matchedOffers),
                        'ambiguous_tjek_offer_count' => $match->ambiguousTjekOfferCount,
                        'missing_tjek_offer_count' => $match->missingTjekOfferCount,
                        'resolved_product_conflict_count' => $match->resolvedConflictCount,
                    ],
                    'offers' => $match->matchedOffers,
                    'issues' => $match->issues,
                ]),
                title: $candidate->title,
            );

            $this->progress($progress, "Mapped {$match->matchedTjekOfferCount} Tjek offers to ".count($match->matchedOffers)." REMA products; logged {$match->ambiguousTjekOfferCount} ambiguous and {$match->missingTjekOfferCount} missing offers.");
        }

        return $payloads;
    }

    public function parse(RawPaperPayload $payload): ParsedPaperInput
    {
        return $this->parser->parse($payload->rawPayload);
    }

    /** @param array<string, mixed> $payload */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new ScraperFetchException("REMA 1000 source is missing {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $payload */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ScraperFetchException('REMA 1000 payload could not be encoded.', previous: $exception);
        }
    }

    private function progress(?callable $progress, string $message): void
    {
        if ($progress !== null) {
            $progress($message);
        }
    }
}
