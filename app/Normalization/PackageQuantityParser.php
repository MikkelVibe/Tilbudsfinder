<?php

namespace App\Normalization;

use App\Normalization\Enums\PackageUnit;
use App\Normalization\Exceptions\NormalizationParseException;
use App\Normalization\ValueObjects\PackageQuantity;
use Brick\Math\BigDecimal;

class PackageQuantityParser
{
    public function __construct(
        private readonly UnitAliasMap $unitAliasMap = new UnitAliasMap,
    ) {}

    public function parse(?string $text): PackageQuantity
    {
        $text = $this->removePurchaseLimitPhrases(trim((string) $text));
        $text = $this->removeSourceCompareUnitPricePhrases($text);

        if ($text === '') {
            throw new NormalizationParseException('Package amount is missing.');
        }

        if ($quantity = $this->parseBestQuantity($text)) {
            return $quantity;
        }

        if ($quantity = $this->parseUnitOnly($text)) {
            return $quantity;
        }

        throw new NormalizationParseException('Package unit is unknown.');
    }

    private function parseBestQuantity(string $text): ?PackageQuantity
    {
        $quantities = [
            ...$this->parseMultipliers($text),
            ...$this->parseAmountRanges($text),
            ...$this->parseAmountUnits($this->removeCompositeQuantityPhrases($text)),
        ];

        $best = null;

        foreach ($quantities as $quantity) {
            if ($best === null || $quantity->normalizedAmount()->isLessThan($best->normalizedAmount())) {
                $best = $quantity;
            }
        }

        return $best;
    }

    private function removePurchaseLimitPhrases(string $text): string
    {
        $text = preg_replace('/\b(?:maks|max)\.\s*\d+(?:[,.]\d+)?\s*(?:stk\.?|styk|stykker)?\s*(?:til\s+denne\s+pris)?\b/iu', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function removeSourceCompareUnitPricePhrases(string $text): string
    {
        $text = preg_replace('/\b(?:\d+(?:[,.]\d+)?|-)\s*pr\.\s*(?:liter|ltr\.?|l\.?|kg|kilo)\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bPr\.\s*(?:liter|ltr\.?|l\.?|kg|kilo)\s*(?:max\.\s*)?(?:\d+(?:[,.]\d+)?|-)/iu', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function removeCompositeQuantityPhrases(string $text): string
    {
        $unitPattern = $this->unitAliasMap->unitPattern();
        $text = preg_replace('/\d+(?:[,.]\d+)?\s*[x×]\s*\d+(?:[,.]\d+)?\s*(?:'.$unitPattern.')\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\d+(?:[,.]\d+)?\s*(?:-|–)\s*\d+(?:[,.]\d+)?\s*(?:'.$unitPattern.')\b/iu', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @return list<PackageQuantity>
     */
    private function parseAmountRanges(string $text): array
    {
        $unitPattern = $this->unitAliasMap->unitPattern();
        preg_match_all('/(?<from>\d+(?:[,.]\d+)?)\s*(?:-|–)\s*(?<to>\d+(?:[,.]\d+)?)\s*(?<unit>'.$unitPattern.')\b/iu', $text, $matches, PREG_SET_ORDER);

        return array_values(array_filter(array_map(function (array $matches): ?PackageQuantity {
            $unit = $this->unitAliasMap->normalize($matches['unit']);

            if (! $unit) {
                return null;
            }

            return new PackageQuantity($this->rangeStartAmount($matches['from'], $matches['to'], $unit), $unit, $matches['unit']);
        }, $matches)));
    }

    private function rangeStartAmount(string $from, string $to, PackageUnit $unit): BigDecimal
    {
        $fromDecimal = $this->decimal($from);
        $toDecimal = $this->decimal($to);

        if ($unit === PackageUnit::Kilogram && $fromDecimal->isGreaterThan('10') && $toDecimal->isLessThanOrEqualTo('10')) {
            return $fromDecimal->dividedBy('1000', 6);
        }

        if ($unit === PackageUnit::Liter && $fromDecimal->isGreaterThan('10') && $toDecimal->isLessThanOrEqualTo('10')) {
            return $fromDecimal->dividedBy('1000', 6);
        }

        return $fromDecimal;
    }

    /**
     * @return list<PackageQuantity>
     */
    private function parseMultipliers(string $text): array
    {
        $unitPattern = $this->unitAliasMap->unitPattern();
        preg_match_all('/(?<count>\d+(?:[,.]\d+)?)\s*[x×]\s*(?<amount>\d+(?:[,.]\d+)?)\s*(?<unit>'.$unitPattern.')\b/iu', $text, $matches, PREG_SET_ORDER);

        return array_values(array_filter(array_map(function (array $matches): ?PackageQuantity {
            $unit = $this->unitAliasMap->normalize($matches['unit']);

            if (! $unit) {
                return null;
            }

            $amount = $this->decimal($matches['count'])->multipliedBy($this->decimal($matches['amount']));

            return new PackageQuantity($amount, $unit, $matches['unit']);
        }, $matches)));
    }

    /**
     * @return list<PackageQuantity>
     */
    private function parseAmountUnits(string $text): array
    {
        $unitPattern = $this->unitAliasMap->unitPattern();
        preg_match_all('/(?<![\d,.\-–x×])(?<amount>\d+(?:[,.]\d+)?)\s*(?<unit>'.$unitPattern.')\b/iu', $text, $matches, PREG_SET_ORDER);

        return array_values(array_filter(array_map(function (array $matches): ?PackageQuantity {
            $unit = $this->unitAliasMap->normalize($matches['unit']);

            if (! $unit) {
                return null;
            }

            return new PackageQuantity($this->decimal($matches['amount']), $unit, $matches['unit']);
        }, $matches)));
    }

    private function parseUnitOnly(string $text): ?PackageQuantity
    {
        $unitPattern = $this->unitAliasMap->unitPattern();

        if (! preg_match('/\b(?<unit>'.$unitPattern.')\b/iu', $text, $matches)) {
            return null;
        }

        $unit = $this->unitAliasMap->normalize($matches['unit']);

        if (! $unit || ! in_array($unit, [PackageUnit::Package, PackageUnit::Tray, PackageUnit::Set, PackageUnit::Pair, PackageUnit::Piece], true)) {
            return null;
        }

        return new PackageQuantity(BigDecimal::of('1'), $unit, $matches['unit'], assumedAmount: true);
    }

    private function decimal(string $value): BigDecimal
    {
        return BigDecimal::of(str_replace(',', '.', $value));
    }
}
