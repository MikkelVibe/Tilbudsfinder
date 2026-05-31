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
        $multipliers = [
            ...$this->parseMultipliers($text),
            ...$this->parseContainerPieceMultipliers($text),
        ];
        $ranges = $this->parseAmountRanges($text);
        $amountUnits = $this->parseAmountUnits($this->removeCompositeQuantityPhrases($text));

        if ($multipliers === [] && $this->hasAmbiguousPlainPhysicalQuantities($amountUnits)) {
            return null;
        }

        $candidateGroups = [
            $multipliers,
            $this->physicalQuantities($ranges),
            $this->physicalQuantities($amountUnits),
            $this->packageLikeQuantities($ranges),
            $this->packageLikeQuantities($amountUnits),
        ];

        foreach ($candidateGroups as $quantities) {
            $best = $this->smallestQuantity($quantities);

            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    /**
     * @param  list<PackageQuantity>  $quantities
     * @return list<PackageQuantity>
     */
    private function physicalQuantities(array $quantities): array
    {
        return array_values(array_filter($quantities, fn (PackageQuantity $quantity): bool => ! $this->isPackageLikeUnit($quantity->unit)));
    }

    /**
     * @param  list<PackageQuantity>  $quantities
     * @return list<PackageQuantity>
     */
    private function packageLikeQuantities(array $quantities): array
    {
        return array_values(array_filter($quantities, fn (PackageQuantity $quantity): bool => $this->isPackageLikeUnit($quantity->unit)));
    }

    /**
     * @param  list<PackageQuantity>  $quantities
     */
    private function smallestQuantity(array $quantities): ?PackageQuantity
    {
        $best = null;

        foreach ($quantities as $quantity) {
            if ($best === null || $quantity->normalizedAmount()->isLessThan($best->normalizedAmount())) {
                $best = $quantity;
            }
        }

        return $best;
    }

    /**
     * @param  list<PackageQuantity>  $quantities
     */
    private function hasAmbiguousPlainPhysicalQuantities(array $quantities): bool
    {
        $distinct = [];
        $counts = [];

        foreach ($this->physicalQuantities($quantities) as $quantity) {
            $key = $quantity->compareUnit()->value.':'.$quantity->normalizedAmount();
            $distinct[$key] = $quantity;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        if (count($distinct) <= 1) {
            return false;
        }

        foreach (array_keys($distinct) as $key) {
            if ($counts[$key] > 1) {
                return false;
            }
        }

        return true;
    }

    private function isPackageLikeUnit(PackageUnit $unit): bool
    {
        return in_array($unit, [PackageUnit::Package, PackageUnit::Tray, PackageUnit::Set, PackageUnit::Pair], true);
    }

    private function removePurchaseLimitPhrases(string $text): string
    {
        $text = preg_replace('/\b(?:maks|max)\.\s*\d+(?:[,.]\d+)?\s*(?:stk\.?|styk|stykker)?\s*(?:til\s+denne\s+pris)?\b/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\bved\s+køb\s+af\s+flere\s+end\s+\d+(?:[,.]\d+)?\s*(?:stk\.?|styk|stykker)\b/iu', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function removeSourceCompareUnitPricePhrases(string $text): string
    {
        $text = preg_replace('/\b(?:\d+(?:[,.]\d+)?|-)\s*pr\.\s*(?:liter|ltr\.?|l\.?|kg|kilo|stk\.?)\b/iu', ' ', $text) ?? $text;
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
    private function parseContainerPieceMultipliers(string $text): array
    {
        $physicalUnitPattern = 'g|gr\.?|gram|kg\.?|kilo|kilogram|ml\.?|milliliter|cl\.?|centiliter|l\.?|ltr\.?|liter|litr';
        $containerPattern = 'kasse|ramme|pakke|pose|æske|aeske|bakke';
        $patterns = [
            '/(?<amount>\d+(?:[,.]\d+)?)\s*(?<unit>'.$physicalUnitPattern.')\b.*?\b(?:'.$containerPattern.')\s+med\s+(?<count>\d+(?:[,.]\d+)?)\s*stk\.?\b/iu',
            '/\b(?:'.$containerPattern.')\s+med\s+(?<count>\d+(?:[,.]\d+)?)\s*stk\.?\b.*?(?<amount>\d+(?:[,.]\d+)?)\s*(?<unit>'.$physicalUnitPattern.')\b/iu',
        ];

        $quantities = [];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $unit = $this->unitAliasMap->normalize($match['unit']);

                if (! $unit) {
                    continue;
                }

                $amount = $this->decimal($match['count'])->multipliedBy($this->decimal($match['amount']));
                $quantities[] = new PackageQuantity($amount, $unit, $match['unit']);
            }
        }

        return $quantities;
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
