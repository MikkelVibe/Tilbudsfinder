<?php

namespace App\Scrapers\Dagrofa;

use App\Imports\DTO\ParsedPaperInput;
use App\Normalization\DTO\ParsedOfferInput;
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\OfferNormalizer;
use App\Scrapers\Exceptions\ScraperParseException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use JsonException;

class DagrofaPaperParser
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
            sourceUrl: $this->optionalString($catalog, 'source_url'),
            rawPayload: $rawPayload,
            metadata: array_filter([
                'dealer_id' => $this->optionalString($catalog, 'dealer_id'),
                'dealer_name' => $this->optionalString($catalog, 'dealer.name'),
                'fetched_offer_count' => Arr::get($catalog, 'fetched_offer_count'),
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
            throw new ScraperParseException('Dagrofa payload is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new ScraperParseException('Dagrofa payload must decode to an object.');
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
            throw new ScraperParseException("Dagrofa payload is missing {$key}.");
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
                throw new ScraperParseException("Dagrofa offer at index {$index} must be an object.");
            }

            if (! $this->isSingleItemDiscount($offer)) {
                continue;
            }

            $parsedOffers[] = $this->parseOffer($offer);
        }

        return $parsedOffers;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function parseOffer(array $offer): ParsedOfferInput
    {
        return new ParsedOfferInput(
            title: $this->requiredString($offer, 'productDisplayName'),
            price: Arr::get($offer, 'discountPrice'),
            packageText: $this->packageText($offer),
            description: $this->optionalString($offer, 'summary'),
            imageUrl: $this->imageUrl($offer),
            sourceOfferId: $this->optionalScalarString($offer, 'sku') ?? $this->optionalScalarString($offer, 'id'),
            sourceProductId: $this->optionalScalarString($offer, 'sku') ?? $this->optionalScalarString($offer, 'id'),
            metadata: array_filter([
                'dagrofa_id' => Arr::get($offer, 'id'),
                'category_id' => Arr::get($offer, 'categoryId'),
                'discount_amount' => Arr::get($offer, 'discountAmount'),
                'normal_price' => Arr::get($offer, 'price'),
                'advertisement_product' => Arr::get($offer, 'advertisementProduct'),
                'discount_max_quantity' => Arr::get($offer, 'discountMaxQuantity'),
            ], static fn (mixed $value): bool => $value !== null),
            sourcePayload: $offer,
        );
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function isSingleItemDiscount(array $offer): bool
    {
        return is_numeric(Arr::get($offer, 'discountPrice'))
            && (float) Arr::get($offer, 'discountPrice') > 0
            && (int) Arr::get($offer, 'discountAmount', 1) === 1;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function packageText(array $offer): ?string
    {
        $summary = $this->optionalString($offer, 'summary');
        $title = $this->optionalString($offer, 'productDisplayName');
        $netQuantity = $this->netQuantityText($summary);

        if ($netQuantity !== null) {
            return $netQuantity;
        }

        if ($summary !== null && preg_match('/^\s*\d+(?:[,.]\d+)?\s*[\p{L}.]+\s*$/u', $summary) === 1) {
            return $summary;
        }

        return trim(implode(' ', array_filter([$title, $summary], static fn (?string $value): bool => $value !== null && trim($value) !== ''))) ?: null;
    }

    private function netQuantityText(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        if (preg_match('/Netto\s+v(?:æ|ae)gt:\s*(?<amount>\d+(?:[,.]\d+)?)\s*(?<unit>gram|g|kg|kilogram|ml|milliliter|cl|centiliter|l|liter)\b/iu', $summary, $matches) !== 1) {
            return null;
        }

        return str_replace(',', '.', $matches['amount']).' '.$matches['unit'];
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function imageUrl(array $offer): ?string
    {
        foreach (['highResImg', 'medResImg', 'lowResImg'] as $key) {
            $url = $this->optionalString($offer, $key);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function validateQuality(ParsedPaperInput $paper): void
    {
        if (count($paper->offers) < self::MINIMUM_PARSED_OFFERS) {
            throw new ScraperParseException('Dagrofa paper must contain at least '.self::MINIMUM_PARSED_OFFERS.' parsed offers.');
        }

        foreach ($paper->offers as $offer) {
            if ($this->offerNormalizer->normalize($offer)->status !== NormalizedOfferStatus::Rejected) {
                return;
            }
        }

        throw new ScraperParseException('Dagrofa paper produced zero publishable offers.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || trim($value) === '') {
            throw new ScraperParseException("Dagrofa payload is missing {$key}.");
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
