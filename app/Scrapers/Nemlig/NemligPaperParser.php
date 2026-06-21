<?php

namespace App\Scrapers\Nemlig;

use App\Imports\DTO\ParsedPaperInput;
use App\Normalization\DTO\ParsedOfferInput;
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\OfferNormalizer;
use App\Scrapers\Exceptions\ScraperParseException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use JsonException;

class NemligPaperParser
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
                'dealer_name' => $this->optionalString($catalog, 'dealer.name'),
                'fetched_offer_count' => Arr::get($catalog, 'fetched_offer_count'),
                'source_strategy' => $this->optionalString($catalog, 'source_strategy'),
                'interval_key' => $this->optionalString($catalog, 'interval_key'),
                'base_source_external_id' => $this->optionalString($catalog, 'base_source_external_id'),
                'skipped_hidden_interval_offer_count' => Arr::get($catalog, 'skipped_hidden_interval_offer_count'),
                'skipped_invalid_interval_offer_count' => Arr::get($catalog, 'skipped_invalid_interval_offer_count'),
                'skipped_irrelevant_offer_count' => Arr::get($catalog, 'skipped_irrelevant_offer_count'),
                'groups' => Arr::get($catalog, 'groups'),
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
            throw new ScraperParseException('Nemlig payload is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new ScraperParseException('Nemlig payload must decode to an object.');
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
            throw new ScraperParseException("Nemlig payload is missing {$key}.");
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
                throw new ScraperParseException("Nemlig offer at index {$index} must be an object.");
            }

            if (! $this->hasCampaignPrice($offer)) {
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
        $packageText = $this->packageText($offer);

        return new ParsedOfferInput(
            title: $this->requiredString($offer, 'Name'),
            price: Arr::get($offer, 'Campaign.CampaignPrice'),
            packageText: $this->isMultiBuyCampaign($offer) ? null : $packageText,
            sourceUnitPrice: $this->sourceUnitPrice($offer, $packageText),
            sourceUnitPriceText: $this->optionalString($offer, 'UnitPrice'),
            description: $this->optionalString($offer, 'Description'),
            imageUrl: $this->optionalString($offer, 'PrimaryImage'),
            sourceOfferId: $this->sourceOfferId($offer),
            sourceProductId: $this->optionalScalarString($offer, 'Id'),
            purchaseLimitText: $this->purchaseLimitText($offer),
            metadata: array_filter([
                'category' => $this->optionalString($offer, 'Category'),
                'subcategory' => $this->optionalString($offer, 'SubCategory'),
                'normal_price' => Arr::get($offer, 'Price'),
                'discount_savings' => Arr::get($offer, 'Campaign.DiscountSavings'),
                'campaign_type' => $this->optionalString($offer, 'Campaign.Type'),
                'campaign_code' => $this->optionalString($offer, 'Campaign.Code'),
                'campaign_attribute' => $this->optionalString($offer, 'CampaignAttribute'),
                'campaign_interval_start' => $this->optionalString($offer, 'Campaign.IntervalStart'),
                'campaign_interval_end' => $this->optionalString($offer, 'Campaign.IntervalEnd'),
                'product_group_heading' => $this->optionalString($offer, '_nemlig_group.Heading'),
                'product_group_id' => $this->optionalString($offer, '_nemlig_group.ProductGroupId'),
                'nutrition' => $this->nutrition($offer),
                'nutrition_basis_unit' => $this->nutritionBasisUnit($offer),
                'declarations_visible' => Arr::get($offer, '_nemlig_detail.Declarations.ShowDeclarations'),
                'attributes' => Arr::get($offer, '_nemlig_detail.Attributes'),
                'traceability' => Arr::get($offer, '_nemlig_detail.Traceability'),
                'raw_detail_payload' => Arr::get($offer, '_nemlig_detail'),
                'declaration' => $this->optionalString($offer, '_nemlig_detail.Text'),
                'technical_description' => $this->optionalString($offer, '_nemlig_detail.TechnicalDescription'),
                'origin_code_description' => $this->optionalString($offer, '_nemlig_detail.OriginCodeDescription'),
                'vk_number' => $this->optionalScalarString($offer, '_nemlig_detail.VkNumber'),
                'labels' => Arr::get($offer, 'Labels'),
            ], static fn (mixed $value): bool => $value !== null),
            sourcePayload: $offer,
        );
    }

    /**
     * @param  array<string, mixed>  $offer
     * @return array<string, string>|null
     */
    private function nutrition(array $offer): ?array
    {
        $declarations = Arr::get($offer, '_nemlig_detail.Declarations');

        if (! is_array($declarations)) {
            return null;
        }

        $nutrition = [];

        foreach ([
            'energy_kcal' => 'EnergyKcal',
            'energy_kj' => 'EnergyKj',
            'protein' => 'NutritionalContentProtein',
            'fat' => 'NutritionalContentFat',
            'carbohydrate' => 'NutritionalContentCarbohydrate',
            'saturated_fat' => 'SaturatedFattyAcid',
            'sugar' => 'Sugar',
            'salt' => 'Salt',
            'dietary_fiber' => 'DietaryFiber',
        ] as $targetKey => $sourceKey) {
            $value = Arr::get($declarations, $sourceKey);

            if (is_string($value) && trim($value) !== '' && trim($value) !== '0') {
                $nutrition[$targetKey] = $value;
            }
        }

        if ($nutrition === [] && Arr::get($declarations, 'ShowDeclarations') !== true) {
            return null;
        }

        return $nutrition;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function nutritionBasisUnit(array $offer): ?string
    {
        $unitPriceLabel = $this->optionalString($offer, 'UnitPriceLabel') ?? $this->optionalString($offer, 'UnitPrice');

        if ($unitPriceLabel !== null && preg_match('/\bkr\.\/\s*(?:ltr|liter|l)\.?\b/iu', $unitPriceLabel)) {
            return 'ml';
        }

        if ($unitPriceLabel !== null && preg_match('/\bkr\.\/\s*(?:kg|kilo)\.?\b/iu', $unitPriceLabel)) {
            return 'g';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function hasCampaignPrice(array $offer): bool
    {
        return is_numeric(Arr::get($offer, 'Campaign.CampaignPrice'))
            && (float) Arr::get($offer, 'Campaign.CampaignPrice') > 0;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function sourceUnitPrice(array $offer, ?string $packageText): string|int|float|null
    {
        if ($this->isMultiBuyCampaign($offer)) {
            return null;
        }

        if (Arr::get($offer, 'Campaign.CampaignUnitPrice') === null) {
            return null;
        }

        if ($this->hasPieceUnitPriceLabel($offer) && $packageText !== null && $this->containsPhysicalPackageQuantity($packageText)) {
            return null;
        }

        return Arr::get($offer, 'Campaign.CampaignUnitPrice');
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function isMultiBuyCampaign(array $offer): bool
    {
        return in_array($this->optionalString($offer, 'Campaign.Type'), [
            'ProductCampaignMixOffer',
            'ProductCampaignBuyXForY',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function hasPieceUnitPriceLabel(array $offer): bool
    {
        return (bool) preg_match('/\bkr\.\/\s*stk\.?\b/iu', $this->optionalString($offer, 'UnitPriceLabel') ?? $this->optionalString($offer, 'UnitPrice') ?? '');
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function packageText(array $offer): ?string
    {
        $description = $this->optionalString($offer, 'Description');

        if ($description !== null) {
            $segments = array_map('trim', explode('/', $description));

            foreach ($segments as $segment) {
                if ($this->containsPackageQuantityMatchingSourceUnit($segment, $offer)) {
                    return $segment;
                }
            }

            foreach ($segments as $segment) {
                if ($this->containsAnyPackageQuantity($segment)) {
                    return $segment;
                }
            }

            return $segments[0] ?? null;
        }

        return $this->optionalString($offer, 'UnitPriceLabel');
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function containsPackageQuantityMatchingSourceUnit(string $text, array $offer): bool
    {
        $unitPriceLabel = $this->optionalString($offer, 'UnitPriceLabel') ?? $this->optionalString($offer, 'UnitPrice');

        if ($unitPriceLabel === null) {
            return false;
        }

        if (preg_match('/\bkr\.\/\s*(?:kg|kilo)\.?\b/iu', $unitPriceLabel)) {
            return (bool) preg_match('/\d+(?:[,.]\d+)?\s*(?:g|gr\.?|gram|kg\.?|kilo|kilogram)\b/iu', $text);
        }

        if (preg_match('/\bkr\.\/\s*(?:ltr|liter|l)\.?\b/iu', $unitPriceLabel)) {
            return (bool) preg_match('/\d+(?:[,.]\d+)?\s*(?:ml\.?|cl\.?|l\.?|ltr\.?|liter)\b/iu', $text);
        }

        if (preg_match('/\bkr\.\/\s*stk\.?\b/iu', $unitPriceLabel)) {
            return (bool) preg_match('/\d+(?:[,.]\d+)?\s*(?:stk\.?)\b/iu', $text);
        }

        return false;
    }

    private function containsAnyPackageQuantity(string $text): bool
    {
        return (bool) preg_match('/\d+(?:[,.]\d+)?\s*(?:g|gr\.?|gram|kg\.?|kilo|kilogram|ml\.?|cl\.?|l\.?|ltr\.?|liter|stk\.?)\b/iu', $text);
    }

    private function containsPhysicalPackageQuantity(string $text): bool
    {
        return (bool) preg_match('/\d+(?:[,.]\d+)?\s*(?:g|gr\.?|gram|kg\.?|kilo|kilogram|ml\.?|cl\.?|l\.?|ltr\.?|liter)\b/iu', $text);
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function sourceOfferId(array $offer): ?string
    {
        $productId = $this->optionalScalarString($offer, 'Id');
        $campaignCode = $this->optionalString($offer, 'Campaign.Code');

        if ($productId === null) {
            return null;
        }

        return $campaignCode === null ? $productId : $productId.'-'.$campaignCode;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function purchaseLimitText(array $offer): ?string
    {
        $maxQuantity = Arr::get($offer, 'Campaign.MaxQuantity');

        if (! is_numeric($maxQuantity) || (int) $maxQuantity <= 0) {
            return null;
        }

        return 'Max '.$maxQuantity.' pr. kunde';
    }

    private function validateQuality(ParsedPaperInput $paper): void
    {
        if (! $this->allowsSmallParsedOfferCount($paper) && count($paper->offers) < self::MINIMUM_PARSED_OFFERS) {
            throw new ScraperParseException('Nemlig paper must contain at least '.self::MINIMUM_PARSED_OFFERS.' parsed offers.');
        }

        foreach ($paper->offers as $offer) {
            if ($this->offerNormalizer->normalize($offer)->status !== NormalizedOfferStatus::Rejected) {
                return;
            }
        }

        throw new ScraperParseException('Nemlig paper produced zero publishable offers.');
    }

    private function allowsSmallParsedOfferCount(ParsedPaperInput $paper): bool
    {
        return ($paper->metadata['source_strategy'] ?? null) === 'nemlig_product_groups'
            && isset($paper->metadata['interval_key']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = Arr::get($payload, $key);

        if (! is_string($value) || trim($value) === '') {
            throw new ScraperParseException("Nemlig payload is missing {$key}.");
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
