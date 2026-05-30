<?php

namespace App\Normalization;

use App\Enums\NormalizationFailureSeverity;
use App\Normalization\DTO\NormalizationIssue;
use App\Normalization\DTO\NormalizedOffer;
use App\Normalization\DTO\ParsedOfferInput;
use App\Normalization\Enums\CompareUnit;
use App\Normalization\Enums\NormalizationIssueCode;
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\Exceptions\NormalizationParseException;
use App\Normalization\ValueObjects\Money;
use App\Normalization\ValueObjects\PackageQuantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class OfferNormalizer
{
    private const UNIT_PRICE_MISMATCH_TOLERANCE = '0.05';

    public function __construct(
        private readonly PriceParser $priceParser = new PriceParser,
        private readonly PackageQuantityParser $packageQuantityParser = new PackageQuantityParser,
    ) {}

    public function normalize(ParsedOfferInput $input): NormalizedOffer
    {
        $issues = [];

        if ($input->isConditional) {
            $issues[] = new NormalizationIssue(
                NormalizationIssueCode::ConditionalOffer,
                'Conditional offers are not published in MVP.',
                NormalizationFailureSeverity::Info,
                'is_conditional',
            );

            return $this->result($input, NormalizedOfferStatus::Rejected, null, null, null, null, null, null, 0, $issues);
        }

        try {
            $price = $this->priceParser->parse($input->price);
        } catch (NormalizationParseException $exception) {
            $issues[] = new NormalizationIssue(
                $input->price === null ? NormalizationIssueCode::PriceMissing : NormalizationIssueCode::PriceInvalid,
                $exception->getMessage(),
                NormalizationFailureSeverity::Error,
                'price',
            );

            return $this->result($input, NormalizedOfferStatus::Rejected, null, null, null, null, null, null, 0, $issues);
        }

        $quantity = $this->parsePackageQuantity($input, $issues);
        $sourceUnitPrice = $this->parseSourceUnitPrice($input, $quantity?->compareUnit(), $issues);
        $calculatedUnitPrice = $quantity ? $this->calculateUnitPrice($price, $quantity) : null;
        $chosenUnitPrice = $calculatedUnitPrice ?? $sourceUnitPrice;
        $confidence = $this->confidence($quantity, $sourceUnitPrice, $calculatedUnitPrice);

        if ($sourceUnitPrice && $calculatedUnitPrice && $this->unitPricesMismatch($sourceUnitPrice, $calculatedUnitPrice)) {
            $issues[] = new NormalizationIssue(
                NormalizationIssueCode::UnitPriceMismatch,
                'Source unit price and calculated unit price differ beyond tolerance.',
                NormalizationFailureSeverity::Warning,
                'unit_price',
                [
                    'source_unit_price' => $sourceUnitPrice->decimal(),
                    'calculated_unit_price' => $calculatedUnitPrice->decimal(),
                    'tolerance' => self::UNIT_PRICE_MISMATCH_TOLERANCE,
                ],
            );
            $chosenUnitPrice = null;
            $confidence = min($confidence, 49);
        }

        $status = $this->status($issues, $quantity, $chosenUnitPrice);

        return $this->result($input, $status, $price, $quantity, $sourceUnitPrice, $calculatedUnitPrice, $chosenUnitPrice, $quantity?->normalizedAmount(), $confidence, $issues);
    }

    /**
     * @param  list<NormalizationIssue>  $issues
     */
    private function parseSourceUnitPrice(ParsedOfferInput $input, ?CompareUnit $compareUnit, array &$issues): ?Money
    {
        $sourceUnitPrice = $input->sourceUnitPrice ?? $this->sourceUnitPriceFromText($input->sourceUnitPriceText, $compareUnit);

        if ($sourceUnitPrice === null) {
            return null;
        }

        try {
            return $this->priceParser->parse($sourceUnitPrice);
        } catch (NormalizationParseException $exception) {
            $issues[] = new NormalizationIssue(
                NormalizationIssueCode::PriceInvalid,
                'Source unit price is invalid: '.$exception->getMessage(),
                NormalizationFailureSeverity::Warning,
                'source_unit_price',
            );

            return null;
        }
    }

    private function sourceUnitPriceFromText(?string $text, ?CompareUnit $compareUnit): ?string
    {
        if ($text === null || $compareUnit === null) {
            return null;
        }

        preg_match_all('/Pr\.\s*(?<unit>liter|ltr\.?|l\.?|kg|kilo|stk\.?)\s*(?:max\.\s*)?(?<price>\d+(?:[,.]\d+)?|-)/iu', $text, $matches, PREG_SET_ORDER);

        $matchingPrices = [];

        foreach ($matches as $match) {
            if ($match['price'] === '-' || $this->compareUnitForSourceUnit($match['unit']) !== $compareUnit) {
                continue;
            }

            $matchingPrices[] = $match['price'];
        }

        return $matchingPrices === [] ? null : end($matchingPrices);
    }

    private function compareUnitForSourceUnit(string $unit): ?CompareUnit
    {
        return match (mb_strtolower(rtrim($unit, '.'))) {
            'kg', 'kilo' => CompareUnit::Kilogram,
            'liter', 'ltr', 'l' => CompareUnit::Liter,
            'stk' => CompareUnit::Piece,
            default => null,
        };
    }

    /**
     * @param  list<NormalizationIssue>  $issues
     */
    private function parsePackageQuantity(ParsedOfferInput $input, array &$issues): ?PackageQuantity
    {
        try {
            return $this->packageQuantityParser->parse($input->packageText);
        } catch (NormalizationParseException $exception) {
            $code = str_contains($exception->getMessage(), 'range')
                ? NormalizationIssueCode::AmountRange
                : ($input->packageText === null || trim($input->packageText) === '' ? NormalizationIssueCode::AmountMissing : NormalizationIssueCode::UnitUnknown);

            $issues[] = new NormalizationIssue(
                $code,
                $exception->getMessage(),
                NormalizationFailureSeverity::Warning,
                'package_text',
                ['package_text' => $input->packageText],
            );

            return null;
        }
    }

    private function calculateUnitPrice(Money $price, PackageQuantity $quantity): Money
    {
        $unitPrice = $price->amount->dividedBy($quantity->normalizedAmount(), 6, RoundingMode::HALF_UP);

        return new Money($unitPrice->toScale(2, RoundingMode::HALF_UP), $price->currency);
    }

    private function unitPricesMismatch(Money $sourceUnitPrice, Money $calculatedUnitPrice): bool
    {
        $difference = $sourceUnitPrice->amount->minus($calculatedUnitPrice->amount)->abs();

        return $difference->isGreaterThan(BigDecimal::of(self::UNIT_PRICE_MISMATCH_TOLERANCE));
    }

    private function confidence(?PackageQuantity $quantity, ?Money $sourceUnitPrice, ?Money $calculatedUnitPrice): int
    {
        if (! $quantity && ! $sourceUnitPrice) {
            return 0;
        }

        if ($quantity?->assumedAmount) {
            return $sourceUnitPrice ? 75 : 60;
        }

        if ($quantity && $calculatedUnitPrice && $sourceUnitPrice) {
            return 100;
        }

        if ($quantity && $calculatedUnitPrice) {
            return 95;
        }

        return 70;
    }

    /**
     * @param  list<NormalizationIssue>  $issues
     */
    private function status(array $issues, ?PackageQuantity $quantity, ?Money $unitPrice): NormalizedOfferStatus
    {
        foreach ($issues as $issue) {
            if ($issue->severity === NormalizationFailureSeverity::Error) {
                return NormalizedOfferStatus::Rejected;
            }
        }

        return $quantity && $unitPrice ? NormalizedOfferStatus::Succeeded : NormalizedOfferStatus::Partial;
    }

    /**
     * @param  list<NormalizationIssue>  $issues
     */
    private function result(
        ParsedOfferInput $input,
        NormalizedOfferStatus $status,
        ?Money $price,
        ?PackageQuantity $quantity,
        ?Money $sourceUnitPrice,
        ?Money $calculatedUnitPrice,
        ?Money $unitPrice,
        ?BigDecimal $normalizedAmount,
        int $confidence,
        array $issues,
    ): NormalizedOffer {
        return new NormalizedOffer(
            status: $status,
            title: $input->cleanedTitle(),
            price: $price,
            packageAmount: $quantity?->amount,
            packageUnit: $quantity?->unit,
            packageUnitOriginal: $quantity?->originalUnit,
            normalizedAmount: $normalizedAmount,
            compareUnit: $quantity?->compareUnit(),
            sourceUnitPrice: $sourceUnitPrice,
            calculatedUnitPrice: $calculatedUnitPrice,
            unitPrice: $unitPrice,
            confidence: $confidence,
            description: $input->description,
            imageUrl: $input->imageUrl,
            sourceOfferId: $input->sourceOfferId,
            sourceProductId: $input->sourceProductId,
            purchaseLimitText: $input->purchaseLimitText,
            metadata: $input->metadata,
            sourcePayload: $input->sourcePayload,
            issues: $issues,
        );
    }
}
