<?php

namespace App\Scrapers\Rema1000;

use App\Imports\DTO\ParsedPaperInput;
use App\Normalization\DTO\ParsedOfferInput;
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\OfferNormalizer;
use App\Scrapers\Exceptions\ScraperParseException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use JsonException;

class Rema1000PaperParser
{
    private const MINIMUM_PARSED_OFFERS = 10;

    public function __construct(
        private readonly OfferNormalizer $offerNormalizer = new OfferNormalizer,
    ) {}

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
            ], static fn (mixed $value): bool => $value !== null),
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

            $parsedOffers[] = isset($offer['algolia'], $offer['product_detail'], $offer['advertised_price'])
                ? $this->parseProductOffer($offer)
                : $this->parseLegacyTjekOffer($offer);
        }

        return $parsedOffers;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function parseProductOffer(array $offer): ParsedOfferInput
    {
        $algolia = $this->arrayValue($offer, 'algolia');
        $detail = $this->arrayValue($offer, 'product_detail');
        $advertisedPrice = $this->arrayValue($offer, 'advertised_price');

        return new ParsedOfferInput(
            title: $this->requiredString($algolia, 'name'),
            price: Arr::get($advertisedPrice, 'price', Arr::get($algolia, 'pricing.price')),
            packageText: $this->optionalString($algolia, 'underline'),
            sourceUnitPrice: $this->sourceUnitPrice($advertisedPrice, $algolia),
            description: $this->optionalString($algolia, 'description_short') ?? $this->optionalString($algolia, 'description'),
            imageUrl: $this->productImageUrl($algolia, $detail),
            sourceOfferId: $this->optionalScalarString($detail, 'id') ?? $this->optionalScalarString($algolia, 'objectID'),
            sourceProductId: $this->optionalScalarString($detail, 'id') ?? $this->optionalScalarString($algolia, 'id'),
            purchaseLimitText: $this->productPurchaseLimitText($advertisedPrice, $algolia),
            metadata: array_filter([
                'brand' => $this->optionalString($algolia, 'hf2'),
                'department_id' => Arr::get($algolia, 'department_id'),
                'department_name' => $this->optionalString($algolia, 'department_name'),
                'category_id' => Arr::get($algolia, 'category_id'),
                'category_name' => $this->optionalString($algolia, 'category_name'),
                'bar_codes' => Arr::get($detail, 'bar_codes', Arr::get($algolia, 'bar_codes')),
                'price_starts_at' => $this->optionalString($advertisedPrice, 'starting_at'),
                'price_ends_at' => $this->optionalString($advertisedPrice, 'ending_at'),
                'is_campaign' => Arr::get($advertisedPrice, 'is_campaign'),
            ], static fn (mixed $value): bool => $value !== null),
            sourcePayload: $offer,
        );
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function parseLegacyTjekOffer(array $offer): ParsedOfferInput
    {
        return new ParsedOfferInput(
            title: $this->requiredString($offer, 'heading'),
            price: Arr::get($offer, 'pricing.price'),
            packageText: $this->packageText($offer),
            description: $this->optionalString($offer, 'description'),
            imageUrl: $this->imageUrl($offer),
            sourceOfferId: $this->optionalString($offer, 'id'),
            purchaseLimitText: $this->purchaseLimitText($offer),
            metadata: array_filter([
                'catalog_page' => Arr::get($offer, 'catalog_page'),
                'catalog_id' => $this->optionalString($offer, 'catalog_id'),
            ], static fn (mixed $value): bool => $value !== null),
            sourcePayload: $offer,
        );
    }

    /**
     * @param  array<string, mixed>  $advertisedPrice
     * @param  array<string, mixed>  $algolia
     */
    private function sourceUnitPrice(array $advertisedPrice, array $algolia): string|int|float|null
    {
        return Arr::get($advertisedPrice, 'compare_unit_price')
            ?? $this->priceFromUnitText($this->optionalString($algolia, 'pricing.price_per_unit'));
    }

    private function priceFromUnitText(?string $unitPrice): ?string
    {
        if ($unitPrice === null || preg_match('/(?<price>\d+(?:[,.]\d+)?)/', $unitPrice, $matches) !== 1) {
            return null;
        }

        return $matches['price'];
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

    /**
     * @param  array<string, mixed>  $offer
     */
    private function packageText(array $offer): ?string
    {
        $parts = array_filter([
            $this->optionalString($offer, 'description'),
            $this->quantityText($offer),
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '');

        if ($parts === []) {
            return null;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function quantityText(array $offer): ?string
    {
        $from = Arr::get($offer, 'quantity.size.from');
        $to = Arr::get($offer, 'quantity.size.to');
        $unit = $this->optionalString($offer, 'quantity.unit.symbol');

        if (! is_numeric($from) || ! is_numeric($to) || $unit === null) {
            return null;
        }

        if ((string) $from === (string) $to) {
            return "{$from} {$unit}";
        }

        return "{$from}-{$to} {$unit}";
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function imageUrl(array $offer): ?string
    {
        foreach (['images.zoom', 'images.view', 'images.thumb'] as $key) {
            $url = $this->optionalString($offer, $key);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function purchaseLimitText(array $offer): ?string
    {
        $description = $this->optionalString($offer, 'description');

        if ($description === null || preg_match('/Note:\s*Maks\.\s*(?<limit>\d+)/iu', $description, $matches) !== 1) {
            return null;
        }

        return 'Maks. '.$matches['limit'];
    }

    private function validateQuality(ParsedPaperInput $paper): void
    {
        if (count($paper->offers) < self::MINIMUM_PARSED_OFFERS) {
            throw new ScraperParseException('REMA 1000 paper must contain at least '.self::MINIMUM_PARSED_OFFERS.' parsed offers.');
        }

        foreach ($paper->offers as $offer) {
            if ($this->offerNormalizer->normalize($offer)->status !== NormalizedOfferStatus::Rejected) {
                return;
            }
        }

        throw new ScraperParseException('REMA 1000 paper produced zero publishable offers.');
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
