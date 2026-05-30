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

        if ($text === '') {
            throw new NormalizationParseException('Package amount is missing.');
        }

        if ($quantity = $this->parseAmountRange($text)) {
            return $quantity;
        }

        if ($quantity = $this->parseMultiplier($text)) {
            return $quantity;
        }

        if ($quantity = $this->parseAmountUnit($text)) {
            return $quantity;
        }

        if ($quantity = $this->parseUnitOnly($text)) {
            return $quantity;
        }

        throw new NormalizationParseException('Package unit is unknown.');
    }

    private function removePurchaseLimitPhrases(string $text): string
    {
        $text = preg_replace('/\b(?:maks|max)\.\s*\d+(?:[,.]\d+)?\s*(?:stk\.?|styk|stykker)?\s*(?:til\s+denne\s+pris)?\b/iu', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function parseAmountRange(string $text): ?PackageQuantity
    {
        $unitPattern = $this->unitAliasMap->unitPattern();

        if (! preg_match('/(?<from>\d+(?:[,.]\d+)?)\s*(?:-|–)\s*(?<to>\d+(?:[,.]\d+)?)\s*(?<unit>'.$unitPattern.')\b/iu', $text, $matches)) {
            return null;
        }

        $unit = $this->unitAliasMap->normalize($matches['unit']);

        if (! $unit) {
            return null;
        }

        $amount = $this->decimal($matches['from'])
            ->plus($this->decimal($matches['to']))
            ->dividedBy('2', 6);

        return new PackageQuantity($amount, $unit, $matches['unit']);
    }

    private function parseMultiplier(string $text): ?PackageQuantity
    {
        $unitPattern = $this->unitAliasMap->unitPattern();

        if (! preg_match('/(?<count>\d+(?:[,.]\d+)?)\s*[x×]\s*(?<amount>\d+(?:[,.]\d+)?)\s*(?<unit>'.$unitPattern.')\b/iu', $text, $matches)) {
            return null;
        }

        $unit = $this->unitAliasMap->normalize($matches['unit']);

        if (! $unit) {
            return null;
        }

        $amount = $this->decimal($matches['count'])->multipliedBy($this->decimal($matches['amount']));

        return new PackageQuantity($amount, $unit, $matches['unit']);
    }

    private function parseAmountUnit(string $text): ?PackageQuantity
    {
        $unitPattern = $this->unitAliasMap->unitPattern();

        if (! preg_match('/(?<amount>\d+(?:[,.]\d+)?)\s*(?<unit>'.$unitPattern.')\b/iu', $text, $matches)) {
            return null;
        }

        $unit = $this->unitAliasMap->normalize($matches['unit']);

        if (! $unit) {
            return null;
        }

        return new PackageQuantity($this->decimal($matches['amount']), $unit, $matches['unit']);
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
