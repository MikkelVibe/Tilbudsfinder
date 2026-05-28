<?php

namespace App\Normalization\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

readonly class Money
{
    public function __construct(
        public BigDecimal $amount,
        public string $currency = 'DKK',
    ) {
    }

    public static function of(BigDecimal|int|string $amount, string $currency = 'DKK'): self
    {
        return new self(BigDecimal::of($amount), $currency);
    }

    public function toScale(int $scale = 2): self
    {
        return new self($this->amount->toScale($scale, RoundingMode::HALF_UP), $this->currency);
    }

    public function decimal(int $scale = 2): string
    {
        return (string) $this->amount->toScale($scale, RoundingMode::HALF_UP);
    }
}
