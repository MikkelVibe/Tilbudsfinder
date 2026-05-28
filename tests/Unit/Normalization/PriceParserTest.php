<?php

namespace Tests\Unit\Normalization;

use App\Normalization\Exceptions\NormalizationParseException;
use App\Normalization\PriceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PriceParserTest extends TestCase
{
    #[DataProvider('danishPriceProvider')]
    public function test_it_parses_danish_price_formats(string|int|float $input, string $expected): void
    {
        $money = (new PriceParser())->parse($input);

        $this->assertSame($expected, $money->decimal());
    }

    /**
     * @return array<string, array{0: string|int|float, 1: string}>
     */
    public static function danishPriceProvider(): array
    {
        return [
            'comma decimal' => ['12,95', '12.95'],
            'dot decimal' => ['12.95', '12.95'],
            'whole kroner string' => ['12', '12.00'],
            'whole kroner int' => [12, '12.00'],
            'dash suffix' => ['12,-', '12.00'],
            'dot suffix' => ['12.', '12.00'],
            'currency text' => ['12,95 kr.', '12.95'],
        ];
    }

    public function test_it_rejects_zero_negative_and_invalid_prices(): void
    {
        $parser = new PriceParser();

        foreach (['0', '-1', 'gratis', ''] as $value) {
            try {
                $parser->parse($value);
                $this->fail("Expected parse exception for {$value}");
            } catch (NormalizationParseException) {
                $this->assertTrue(true);
            }
        }
    }
}
