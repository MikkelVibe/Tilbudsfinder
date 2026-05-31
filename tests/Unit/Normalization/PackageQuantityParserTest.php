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
        $parser = new PackageQuantityParser;

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
        $quantity = (new PackageQuantityParser)->parse('pakke');

        $this->assertSame(PackageUnit::Package, $quantity->unit);
        $this->assertSame(CompareUnit::Piece, $quantity->compareUnit());
        $this->assertSame('1', (string) $quantity->normalizedAmount());
        $this->assertTrue($quantity->assumedAmount);
    }

    public function test_it_uses_smallest_amount_for_ranges(): void
    {
        $quantity = (new PackageQuantityParser)->parse('500-750 g');

        $this->assertSame(PackageUnit::Gram, $quantity->unit);
        $this->assertSame('500', (string) $quantity->amount);
        $this->assertSame('0.500000', (string) $quantity->normalizedAmount());
        $this->assertFalse($quantity->assumedAmount);
    }

    public function test_it_accepts_pcs_as_piece_unit(): void
    {
        $quantity = (new PackageQuantityParser)->parse('1 pcs');

        $this->assertSame(PackageUnit::Piece, $quantity->unit);
        $this->assertSame(CompareUnit::Piece, $quantity->compareUnit());
        $this->assertSame('1', (string) $quantity->normalizedAmount());
    }

    public function test_it_accepts_dagrofa_uppercase_aliases_and_meter(): void
    {
        $parser = new PackageQuantityParser;

        $pieces = $parser->parse('40 ST');
        $liters = $parser->parse('1 LT');
        $meters = $parser->parse('25 M');

        $this->assertSame(PackageUnit::Piece, $pieces->unit);
        $this->assertSame(CompareUnit::Piece, $pieces->compareUnit());
        $this->assertSame('40', (string) $pieces->normalizedAmount());

        $this->assertSame(PackageUnit::Liter, $liters->unit);
        $this->assertSame(CompareUnit::Liter, $liters->compareUnit());
        $this->assertSame('1', (string) $liters->normalizedAmount());

        $this->assertSame(PackageUnit::Meter, $meters->unit);
        $this->assertSame(CompareUnit::Meter, $meters->compareUnit());
        $this->assertSame('25', (string) $meters->normalizedAmount());
    }

    public function test_it_ignores_purchase_limits_before_package_quantities(): void
    {
        $parser = new PackageQuantityParser;

        $skyr = $parser->parse('Note: Maks. 6 | Max. 6 stk. til denne pris. Frit valg. 1 kg. Flere varianter.');
        $soda = $parser->parse('Note: Maks. 12 | 1,5 liter. Flere varianter. MAX. 12 STK TIL DENNE PRIS.');
        $coffee = $parser->parse('Note: Maks. 6 | 1 kg. Ved køb af flere end 6 stk. pr. variant pr. dag er prisen 139.95 pr. stk. 1 kg');

        $this->assertSame(PackageUnit::Kilogram, $skyr->unit);
        $this->assertSame('1', (string) $skyr->normalizedAmount());

        $this->assertSame(PackageUnit::Liter, $soda->unit);
        $this->assertSame('1.5', (string) $soda->normalizedAmount());

        $this->assertSame(PackageUnit::Kilogram, $coffee->unit);
        $this->assertSame('1', (string) $coffee->normalizedAmount());
    }

    public function test_it_uses_smallest_total_amount_for_mixed_multipacks(): void
    {
        $quantity = (new PackageQuantityParser)->parse('8x50 cl Monster eller 24x25 cl Coca-Cola. Pr. liter 19.75.');

        $this->assertSame(PackageUnit::Centiliter, $quantity->unit);
        $this->assertSame(CompareUnit::Liter, $quantity->compareUnit());
        $this->assertSame('400', (string) $quantity->amount);
        $this->assertSame('4.000000', (string) $quantity->normalizedAmount());
    }

    public function test_it_prefers_explicit_multiplier_over_package_fallback(): void
    {
        $quantity = (new PackageQuantityParser)->parse('6 x 33 cl. Literpris 9,09 + pant. Frit valg. 1 pakke. 6 x 33 cl');

        $this->assertSame(PackageUnit::Centiliter, $quantity->unit);
        $this->assertSame(CompareUnit::Liter, $quantity->compareUnit());
        $this->assertSame('198', (string) $quantity->amount);
        $this->assertSame('1.980000', (string) $quantity->normalizedAmount());
    }

    public function test_it_parses_container_with_piece_count_and_physical_amount(): void
    {
        $quantity = (new PackageQuantityParser)->parse('33 cl. Ex. pant Kasse med 24 stk. 84.00/10.61 pr. liter 33 cl');

        $this->assertSame(PackageUnit::Centiliter, $quantity->unit);
        $this->assertSame(CompareUnit::Liter, $quantity->compareUnit());
        $this->assertSame('792', (string) $quantity->amount);
        $this->assertSame('7.920000', (string) $quantity->normalizedAmount());
    }

    public function test_it_prefers_piece_count_over_package_like_container(): void
    {
        $quantity = (new PackageQuantityParser)->parse('Str. S/M. 15 stk. Stk.-pris 1,67. 1 bakke. 15 pcs');

        $this->assertSame(PackageUnit::Piece, $quantity->unit);
        $this->assertSame(CompareUnit::Piece, $quantity->compareUnit());
        $this->assertSame('15', (string) $quantity->normalizedAmount());
    }

    public function test_it_rejects_ambiguous_plain_physical_quantities(): void
    {
        $this->expectException(NormalizationParseException::class);

        (new PackageQuantityParser)->parse('50 cl. 65 ml. 70 cl. Flere varianter. Pr. liter max. 40.- Frit valg. 50-100 cl');
    }

    public function test_it_prefers_smallest_relevant_quantity_over_source_unit_price_text(): void
    {
        $quantity = (new PackageQuantityParser)->parse('Pr. stk. 2-3 kg. Kan steges hel. Pr. kg max. 174.50.');

        $this->assertSame(PackageUnit::Kilogram, $quantity->unit);
        $this->assertSame('2', (string) $quantity->amount);
        $this->assertSame('2', (string) $quantity->normalizedAmount());
    }

    public function test_it_handles_mixed_metric_range_notation(): void
    {
        $quantity = (new PackageQuantityParser)->parse('900-1,26 kg. Flere varianter. Pr. kg max. 83.33.');

        $this->assertSame(PackageUnit::Kilogram, $quantity->unit);
        $this->assertSame('0.900000', (string) $quantity->amount);
        $this->assertSame('0.900000', (string) $quantity->normalizedAmount());
    }
}
