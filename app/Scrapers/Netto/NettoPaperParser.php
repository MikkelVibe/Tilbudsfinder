<?php

namespace App\Scrapers\Netto;

use App\Imports\DTO\ParsedPaperInput;
use App\Normalization\DTO\ParsedOfferInput;
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\OfferNormalizer;
use App\Scrapers\Exceptions\ScraperParseException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use JsonException;

class NettoPaperParser
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
            sourceUrl: 'https://netto.dk/tilbudsavis/',
            rawPayload: $rawPayload,
            metadata: array_filter([
                'dealer_id' => $this->optionalString($catalog, 'dealer_id'),
                'dealer_name' => $this->optionalString($catalog, 'dealer.name'),
                'offer_count' => Arr::get($catalog, 'offer_count'),
                'fetched_offer_count' => Arr::get($catalog, 'fetched_offer_count'),
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
            throw new ScraperParseException('Netto payload is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new ScraperParseException('Netto payload must decode to an object.');
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
            throw new ScraperParseException("Netto payload is missing {$key}.");
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
                throw new ScraperParseException("Netto offer at index {$index} must be an object.");
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
            title: $this->requiredString($offer, 'heading'),
            price: Arr::get($offer, 'pricing.price'),
            packageText: $this->packageText($offer),
            sourceUnitPrice: $this->sourceUnitPrice($offer),
            description: $this->optionalString($offer, 'description'),
            imageUrl: $this->imageUrl($offer),
            sourceOfferId: $this->optionalString($offer, 'id'),
            purchaseLimitText: $this->purchaseLimitText($offer),
            metadata: array_filter([
                'catalog_page' => Arr::get($offer, 'catalog_page'),
                'catalog_id' => $this->optionalString($offer, 'catalog_id'),
                'run_from' => $this->optionalString($offer, 'run_from'),
                'run_till' => $this->optionalString($offer, 'run_till'),
            ], static fn (mixed $value): bool => $value !== null),
            sourcePayload: $offer,
        );
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
        $piecesFrom = Arr::get($offer, 'quantity.pieces.from');
        $piecesTo = Arr::get($offer, 'quantity.pieces.to');
        $from = Arr::get($offer, 'quantity.size.from');
        $to = Arr::get($offer, 'quantity.size.to');
        $unit = $this->optionalString($offer, 'quantity.unit.symbol');

        if (! is_numeric($from) || ! is_numeric($to) || $unit === null) {
            return null;
        }

        $amount = (string) $from === (string) $to ? "{$from} {$unit}" : "{$from}-{$to} {$unit}";

        if (is_numeric($piecesFrom) && is_numeric($piecesTo) && (int) $piecesFrom > 1 && (string) $piecesFrom === (string) $piecesTo) {
            return ((int) $piecesFrom).' x '.$amount;
        }

        return $amount;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function sourceUnitPrice(array $offer): ?string
    {
        $description = $this->optionalString($offer, 'description');

        if ($description === null || preg_match('/Pr\.\s*(?:liter|kg|stk\.)\s*(?:max\.\s*)?(?<price>\d+(?:[,.]\d+)?)/iu', $description, $matches) !== 1) {
            return null;
        }

        return $matches['price'];
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
            throw new ScraperParseException('Netto paper must contain at least '.self::MINIMUM_PARSED_OFFERS.' parsed offers.');
        }

        foreach ($paper->offers as $offer) {
            if ($this->offerNormalizer->normalize($offer)->status !== NormalizedOfferStatus::Rejected) {
                return;
            }
        }

        throw new ScraperParseException('Netto paper produced zero publishable offers.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || trim($value) === '') {
            throw new ScraperParseException("Netto payload is missing {$key}.");
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
}
