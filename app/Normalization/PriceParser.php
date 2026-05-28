<?php

namespace App\Normalization;

use App\Normalization\Exceptions\NormalizationParseException;
use App\Normalization\ValueObjects\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

class PriceParser
{
    public function parse(string|int|float|null $value): Money
    {
        if ($value === null) {
            throw new NormalizationParseException('Price is missing.');
        }

        $normalized = $this->normalizePriceText((string) $value);

        if ($normalized === null) {
            throw new NormalizationParseException('Price is invalid.');
        }

        $amount = BigDecimal::of($normalized)->toScale(2, RoundingMode::HALF_UP);

        if ($amount->isLessThanOrEqualTo(0)) {
            throw new NormalizationParseException('Price must be positive.');
        }

        return new Money($amount);
    }

    private function normalizePriceText(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = mb_strtolower($value);
        $value = str_replace(['kr.', 'kr', 'dkk', ',-'], ['', '', '', '.00'], $value);
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        $value = rtrim($value, '.');

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace('.', '', $value);
        }

        $value = str_replace(',', '.', $value);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        return $value;
    }
}
