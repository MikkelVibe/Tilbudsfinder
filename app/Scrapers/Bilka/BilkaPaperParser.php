<?php

namespace App\Scrapers\Bilka;

use App\Imports\DTO\ParsedPaperInput;
use App\Normalization\DTO\ParsedOfferInput;
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\OfferNormalizer;
use App\Scrapers\Exceptions\ScraperParseException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use JsonException;

class BilkaPaperParser
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
            sourceUrl: 'https://www.bilka.dk/tilbudsavis/',
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
            throw new ScraperParseException('Bilka payload is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new ScraperParseException('Bilka payload must decode to an object.');
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
            throw new ScraperParseException("Bilka payload is missing {$key}.");
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
                throw new ScraperParseException("Bilka offer at index {$index} must be an object.");
            }

            foreach ($this->parseOfferVariants($offer) as $parsedOffer) {
                $parsedOffers[] = $parsedOffer;
            }
        }

        return $parsedOffers;
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return list<ParsedOfferInput>
     */
    private function parseOfferVariants(array $offer): array
    {
        $variants = $this->descriptionVariants($offer);

        if (count($variants) < 2) {
            return [$this->parseOffer($offer)];
        }

        return array_map(
            fn (array $variant, int $index): ParsedOfferInput => $this->parseSplitOffer($offer, $variant, $index),
            $variants,
            array_keys($variants),
        );
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
            sourceUnitPriceText: $this->optionalString($offer, 'description'),
            description: $this->optionalString($offer, 'description'),
            imageUrl: $this->imageUrl($offer),
            sourceOfferId: $this->optionalString($offer, 'id'),
            isConditional: $this->isConditional($offer),
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
     * @param  array{title: string, quantity_text: string}  $variant
     */
    private function parseSplitOffer(array $offer, array $variant, int $index): ParsedOfferInput
    {
        $originalSourceOfferId = $this->optionalString($offer, 'id');
        $variantMetadata = [
            'original_source_offer_id' => $originalSourceOfferId,
            'variant_index' => $index + 1,
            'variant_source' => 'tjek_description_split',
            'split_confidence' => 90,
        ];

        return new ParsedOfferInput(
            title: $variant['title'].' '.$variant['quantity_text'],
            price: Arr::get($offer, 'pricing.price'),
            packageText: $variant['quantity_text'].' '.$variant['title'],
            sourceUnitPriceText: null,
            description: $this->optionalString($offer, 'description'),
            imageUrl: $this->imageUrl($offer),
            sourceOfferId: $originalSourceOfferId === null ? null : $originalSourceOfferId.':variant-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
            isConditional: false,
            purchaseLimitText: $this->purchaseLimitText($offer),
            metadata: array_filter([
                'catalog_page' => Arr::get($offer, 'catalog_page'),
                'catalog_id' => $this->optionalString($offer, 'catalog_id'),
                'run_from' => $this->optionalString($offer, 'run_from'),
                'run_till' => $this->optionalString($offer, 'run_till'),
                ...$variantMetadata,
            ], static fn (mixed $value): bool => $value !== null),
            sourcePayload: [
                ...$offer,
                '_parsed_variant' => $variantMetadata,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return list<array{title: string, quantity_text: string}>
     */
    private function descriptionVariants(array $offer): array
    {
        $description = $this->optionalString($offer, 'description');

        if ($description === null || ! is_numeric(Arr::get($offer, 'pricing.price')) || $this->isConditional($offer)) {
            return [];
        }

        $quantityUnitPattern = 'g|kg|ml|cl|l|liter|stk';
        preg_match_all(
            '/(?:^|[\r\n|]\s*|\.\s+)(?<amount>\d+(?:[,.]\d+)?)\s*(?<unit>'.$quantityUnitPattern.')\.?\s+(?<names>.*?)(?=(?:[\r\n]|\.\s+)\d+(?:[,.]\d+)?\s*(?:'.$quantityUnitPattern.')\b|(?:[\r\n]|\.\s+)(?:Pr\.|Ekskl\.|Frit valg\.)|$)/iu',
            $description,
            $matches,
            PREG_SET_ORDER,
        );

        $variants = [];
        $compareDomains = [];
        $titlePrefix = $this->variantTitlePrefix($this->requiredString($offer, 'heading'));

        if ($titlePrefix === null) {
            return [];
        }

        foreach ($matches as $match) {
            $quantityText = $this->cleanText($match['amount'].' '.$match['unit']);
            $domain = $this->quantityCompareDomain($match['unit']);

            if ($domain === null) {
                return [];
            }

            $compareDomains[$domain] = true;

            foreach ($this->variantNames($match['names']) as $name) {
                $title = $this->variantTitle($name, $titlePrefix);

                if ($title === null) {
                    continue;
                }

                $variants[] = [
                    'title' => $title,
                    'quantity_text' => $quantityText,
                ];
            }
        }

        if (count($compareDomains) !== 1 || count($variants) < 2) {
            return [];
        }

        return $variants;
    }

    private function variantTitlePrefix(string $heading): ?string
    {
        if (preg_match('/^(?<brand>[\pL\d][\pL\d-]*)\s+marked\b/iu', $heading, $matches) !== 1) {
            return null;
        }

        return $matches['brand'];
    }

    /**
     * @return list<string>
     */
    private function variantNames(string $names): array
    {
        $names = preg_replace('/\b(?:flere varianter|frit valg)\b\.?/iu', ' ', $names) ?? $names;
        $names = str_replace([' eller ', ' Eller '], ',', $names);

        return array_values(array_filter(array_map(
            fn (string $name): string => $this->cleanText($name),
            preg_split('/,/u', $names) ?: [],
        ), static fn (string $name): bool => $name !== ''));
    }

    private function variantTitle(string $name, ?string $prefix): ?string
    {
        if (preg_match('/\b(?:pr\.|færdigblandet|embl|maks|max|note|flere varianter|frit valg)\b/iu', $name) === 1) {
            return null;
        }

        if ($prefix !== null && preg_match('/^'.preg_quote($prefix, '/').'\b/iu', $name) !== 1) {
            $name = $prefix.' '.$name;
        }

        return $this->cleanText($name);
    }

    private function quantityCompareDomain(string $unit): ?string
    {
        return match (mb_strtolower(rtrim($unit, '.'))) {
            'g', 'kg' => 'weight',
            'ml', 'cl', 'l', 'liter' => 'volume',
            'stk' => 'count',
            default => null,
        };
    }

    private function cleanText(string $text): string
    {
        $text = trim($text, " \t\n\r\0\x0B.-");

        return preg_replace('/\s+/u', ' ', $text) ?? $text;
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
    private function isConditional(array $offer): bool
    {
        $description = $this->optionalString($offer, 'description');

        if ($description === null || ! preg_match('/\b(?:app-pris|plus pris|bilka plus|gælder kun med bilka plus appen)\b/iu', $description)) {
            return false;
        }

        $structuredPrice = Arr::get($offer, 'pricing.price');

        if (! is_numeric($structuredPrice)) {
            return true;
        }

        $appPrice = $this->appPrice($description);

        if ($appPrice !== null && (float) $structuredPrice === (float) $appPrice) {
            return true;
        }

        return ! $this->containsGeneralPrice($description, (float) $structuredPrice);
    }

    private function appPrice(string $description): ?float
    {
        if (preg_match('/\bApp-pris\s*(?<price>\d+(?:[,.]\d+)?)/iu', $description, $matches) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $matches['price']);
    }

    private function containsGeneralPrice(string $description, float $price): bool
    {
        $pricePattern = preg_quote(rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.'), '/');
        $pricePattern = str_replace('\.', '[,.]', $pricePattern);

        return preg_match('/\b(?:FRIT\s+VALG\.?)\s*'.$pricePattern.'\b/iu', $description) === 1
            || preg_match('/\b'.$pricePattern.'\.\s*(?:Italien|Frankrig|Spanien|Australien|Danmark)\b/iu', $description) === 1;
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
            throw new ScraperParseException('Bilka paper must contain at least '.self::MINIMUM_PARSED_OFFERS.' parsed offers.');
        }

        foreach ($paper->offers as $offer) {
            if ($this->offerNormalizer->normalize($offer)->status !== NormalizedOfferStatus::Rejected) {
                return;
            }
        }

        throw new ScraperParseException('Bilka paper produced zero publishable offers.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || trim($value) === '') {
            throw new ScraperParseException("Bilka payload is missing {$key}.");
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
