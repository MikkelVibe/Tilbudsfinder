<?php

namespace App\Normalization;

use App\Normalization\Enums\PackageUnit;
use App\Normalization\Exceptions\NormalizationParseException;
use App\Normalization\ValueObjects\PackageQuantity;
use Brick\Math\BigDecimal;

class PackageQuantityParser
{
    public function __construct(
        private readonly UnitAliasMap $unitAliasMap = new UnitAliasMap(),
    ) {
    }

    public function parse(?string $text): PackageQuantity
    {
        $text = trim((string) $text);

        if ($text === '') {
            throw new NormalizationParseException('Package amount is missing.');
        }

        if ($this->containsRange($text)) {
            throw new NormalizationParseException('Package amount range cannot be normalized confidently.');
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

    private function containsRange(string $text): bool
    {
        $unitPattern = $this->unitAliasMap->unitPattern();

        return preg_match('/\d+(?:[,.]\d+)?\s*(?:-|–)\s*\d+(?:[,.]\d+)?\s*(?:'.$unitPattern.')\b/iu', $text) === 1;
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
