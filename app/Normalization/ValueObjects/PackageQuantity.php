<?php

namespace App\Normalization\ValueObjects;

use App\Normalization\Enums\CompareUnit;
use App\Normalization\Enums\PackageUnit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

readonly class PackageQuantity
{
    public function __construct(
        public BigDecimal $amount,
        public PackageUnit $unit,
        public string $originalUnit,
        public bool $assumedAmount = false,
    ) {
    }

    public function compareUnit(): CompareUnit
    {
        return $this->unit->compareUnit();
    }

    public function normalizedAmount(): BigDecimal
    {
        return match ($this->unit) {
            PackageUnit::Gram => $this->amount->dividedBy('1000', 6, RoundingMode::HALF_UP),
            PackageUnit::Milliliter => $this->amount->dividedBy('1000', 6, RoundingMode::HALF_UP),
            PackageUnit::Centiliter => $this->amount->dividedBy('100', 6, RoundingMode::HALF_UP),
            default => $this->amount,
        };
    }
}
