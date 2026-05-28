<?php

namespace Tests\Unit\Normalization;

use App\Normalization\Enums\CompareUnit;
use App\Normalization\Enums\PackageUnit;
use App\Normalization\Exceptions\NormalizationParseException;
use App\Normalization\PackageQuantityParser;
use PHPUnit\Framework\TestCase;

class PackageQuantityParserTest extends TestCase
{
    public function test_it_parses_weight_volume_count_and_multiplier_quantities(): void
    {
        $parser = new PackageQuantityParser();

        $weight = $parser->parse('500GR.');
        $volume = $parser->parse('1,5 LTR.');
        $multiplier = $parser->parse('3 x 500 ml');
        $count = $parser->parse('6 stk');

        $this->assertSame(PackageUnit::Gram, $weight->unit);
        $this->assertSame(CompareUnit::Kilogram, $weight->compareUnit());
        $this->assertSame('0.500000', (string) $weight->normalizedAmount());

        $this->assertSame(PackageUnit::Liter, $volume->unit);
        $this->assertSame(CompareUnit::Liter, $volume->compareUnit());
        $this->assertSame('1.5', (string) $volume->normalizedAmount());

        $this->assertSame(PackageUnit::Milliliter, $multiplier->unit);
        $this->assertSame('1.500000', (string) $multiplier->normalizedAmount());

        $this->assertSame(PackageUnit::Piece, $count->unit);
        $this->assertSame(CompareUnit::Piece, $count->compareUnit());
        $this->assertSame('6', (string) $count->normalizedAmount());
    }

    public function test_it_assumes_one_for_package_like_units_without_count(): void
    {
        $quantity = (new PackageQuantityParser())->parse('pakke');

        $this->assertSame(PackageUnit::Package, $quantity->unit);
        $this->assertSame(CompareUnit::Piece, $quantity->compareUnit());
        $this->assertSame('1', (string) $quantity->normalizedAmount());
        $this->assertTrue($quantity->assumedAmount);
    }

    public function test_it_rejects_amount_ranges(): void
    {
        $this->expectException(NormalizationParseException::class);

        (new PackageQuantityParser())->parse('500-750 g');
    }
}
