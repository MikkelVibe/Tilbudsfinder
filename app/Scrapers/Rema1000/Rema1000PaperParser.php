<?php

namespace App\Scrapers\Rema1000;

use App\Imports\DTO\ImportIssueInput;
use App\Imports\DTO\ParsedPaperInput;
use App\Normalization\DTO\ParsedOfferInput;
use App\Scrapers\Exceptions\ScraperParseException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use JsonException;

class Rema1000PaperParser
{
    public function parse(string $rawPayload): ParsedPaperInput
    {
        $payload = $this->decode($rawPayload);
        $catalog = $this->arrayValue($payload, 'catalog');
        $offers = $this->arrayValue($payload, 'offers');

        $paper = new ParsedPaperInput(
            sourceExternalId: $this->requiredString($catalog, 'id'),
            activeFrom: CarbonImmutable::parse($this->requiredString($catalog, 'run_from')),
            activeUntil: CarbonImmutable::parse($this->requiredString($catalog, 'run_till')),
            offers: $this->parseOffers($offers),
            title: $this->optionalString($catalog, 'label'),
            sourceUrl: 'https://shop.rema1000.dk/avisvarer',
            rawPayload: $rawPayload,
            issues: $this->parseImportIssues(Arr::get($payload, 'issues', [])),
            metadata: array_filter([
                'dealer_id' => $this->optionalString($catalog, 'dealer_id'),
                'dealer_name' => $this->optionalString($catalog, 'dealer.name'),
                'offer_count' => Arr::get($catalog, 'offer_count'),
                'fetched_offer_count' => Arr::get($catalog, 'fetched_offer_count'),
                'fetched_product_offer_count' => Arr::get($catalog, 'fetched_product_offer_count'),
                'offer_count_mismatch' => Arr::get($catalog, 'offer_count_mismatch'),
                'page_count' => Arr::get($catalog, 'page_count'),
                'pdf_url' => $this->optionalString($catalog, 'pdf_url'),
                'source_strategy' => $this->optionalString($catalog, 'source_strategy'),
                'matched_tjek_offer_count' => Arr::get($catalog, 'matched_tjek_offer_count'),
                'matched_product_count' => Arr::get($catalog, 'matched_product_count'),
                'ambiguous_tjek_offer_count' => Arr::get($catalog, 'ambiguous_tjek_offer_count'),
                'missing_tjek_offer_count' => Arr::get($catalog, 'missing_tjek_offer_count'),
                'resolved_product_conflict_count' => Arr::get($catalog, 'resolved_product_conflict_count'),
            ], static fn (mixed $value): bool => $value !== null),
            reconcileExistingPaper: true,
        );

        $this->validateQuality($paper);

        return $paper;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $rawPayload): array
    {
        try {
            $payload = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ScraperParseException('REMA 1000 payload is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new ScraperParseException('REMA 1000 payload must decode to an object.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    private function arrayValue(array $payload, string $key): array
    {
        $value = Arr::get($payload, $key);

        if (! is_array($value)) {
            throw new ScraperParseException("REMA 1000 payload is missing {$key}.");
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @return list<ParsedOfferInput>
     */
    private function parseOffers(array $offers): array
    {
        $parsedOffers = [];

        foreach ($offers as $index => $offer) {
            if (! is_array($offer)) {
                throw new ScraperParseException("REMA 1000 offer at index {$index} must be an object.");
            }

            if (! isset($offer['rema_product'], $offer['advertised_price'], $offer['tjek_offer'])) {
                throw new ScraperParseException("REMA 1000 offer at index {$index} must contain rema_product, advertised_price, and tjek_offer.");
            }

            $parsedOffers[] = $this->parseMatchedProductOffer($offer);
        }

        return $parsedOffers;
    }

    /** @param array<string, mixed> $offer */
    private function parseMatchedProductOffer(array $offer): ParsedOfferInput
    {
        $product = $this->arrayValue($offer, 'rema_product');
        $advertisedPrice = $this->arrayValue($offer, 'advertised_price');
        $tjekOffer = $this->arrayValue($offer, 'tjek_offer');

        return new ParsedOfferInput(
            title: $this->requiredString($product, 'name'),
            price: Arr::get($advertisedPrice, 'price'),
            packageText: $this->optionalString($product, 'underline'),
            sourceUnitPrice: Arr::get($advertisedPrice, 'compare_unit_price'),
            description: $this->optionalString($product, 'description') ?? $this->optionalString($tjekOffer, 'description'),
            imageUrl: $this->productImageUrl($product, $product),
            sourceOfferId: $this->optionalString($tjekOffer, 'id'),
            sourceProductId: $this->optionalScalarString($product, 'id'),
            purchaseLimitText: $this->productPurchaseLimitText($advertisedPrice, $product),
            metadata: array_filter([
                'department_id' => Arr::get($product, 'department.id'),
                'category_id' => Arr::get($product, 'category.id'),
                'category' => $this->optionalString($product, 'department.name'),
                'subcategory' => $this->optionalString($product, 'category.name'),
                'declaration' => $this->optionalString($product, 'declaration'),
                'bar_codes' => Arr::get($product, 'barcodes'),
                'price_starts_at' => $this->optionalString($advertisedPrice, 'starting_at'),
                'price_ends_at' => $this->optionalString($advertisedPrice, 'ending_at'),
                'is_campaign' => Arr::get($advertisedPrice, 'is_campaign'),
                'match_score' => Arr::get($offer, 'score'),
                'catalog_page' => Arr::get($tjekOffer, 'catalog_page'),
            ], static fn (mixed $value): bool => $value !== null),
            sourcePayload: $offer,
        );
    }

    /**
     * @param  array<string, mixed>  $algolia
     * @param  array<string, mixed>  $detail
     */
    private function productImageUrl(array $algolia, array $detail): ?string
    {
        foreach (['images.0.large', 'images.0.medium', 'images.0.small'] as $key) {
            $url = $this->optionalString($algolia, $key) ?? $this->optionalString($detail, $key);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $advertisedPrice
     * @param  array<string, mixed>  $algolia
     */
    private function productPurchaseLimitText(array $advertisedPrice, array $algolia): ?string
    {
        $maxQuantity = Arr::get($advertisedPrice, 'max_quantity', Arr::get($algolia, 'pricing.max_quantity'));

        if (! is_numeric($maxQuantity) || (int) $maxQuantity <= 0) {
            return null;
        }

        return 'Maks. '.(int) $maxQuantity;
    }

    private function validateQuality(ParsedPaperInput $paper): void
    {
        $fetched = (int) ($paper->metadata['fetched_offer_count'] ?? -1);
        $accounted = (int) ($paper->metadata['matched_tjek_offer_count'] ?? 0)
            + (int) ($paper->metadata['ambiguous_tjek_offer_count'] ?? 0)
            + (int) ($paper->metadata['missing_tjek_offer_count'] ?? 0);

        if ($fetched < 0 || $fetched !== $accounted) {
            throw new ScraperParseException('REMA 1000 Tjek accounting does not reconcile matched, ambiguous, and missing offers.');
        }

        if (count($paper->offers) !== (int) ($paper->metadata['matched_product_count'] ?? -1)) {
            throw new ScraperParseException('REMA 1000 matched product accounting does not reconcile with parsed offers.');
        }
    }

    /**
     * @return list<ImportIssueInput>
     */
    private function parseImportIssues(mixed $issues): array
    {
        if (! is_array($issues) || ! array_is_list($issues)) {
            throw new ScraperParseException('REMA 1000 import issues must be a list.');
        }

        return array_map(function (mixed $issue): ImportIssueInput {
            if (! is_array($issue)) {
                throw new ScraperParseException('REMA 1000 import issue must be an object.');
            }

            return new ImportIssueInput(
                code: $this->requiredString($issue, 'code'),
                message: $this->requiredString($issue, 'message'),
                sourceCatalogId: $this->optionalString($issue, 'source_catalog_id'),
                sourceOfferId: $this->optionalString($issue, 'source_offer_id'),
                context: is_array(Arr::get($issue, 'context')) ? Arr::get($issue, 'context') : [],
            );
        }, $issues);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || trim($value) === '') {
            throw new ScraperParseException("REMA 1000 payload is missing {$key}.");
        }

        return $value;
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
    private function optionalScalarString(array $payload, string $key): ?string
    {
        $value = Arr::get($payload, $key);

        if ((is_string($value) || is_int($value)) && trim((string) $value) !== '') {
            return (string) $value;
        }

        return null;
    }
}
