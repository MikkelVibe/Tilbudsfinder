<?php

namespace Tests\Unit\Normalization;

use App\Normalization\Enums\CompareUnit;
use App\Normalization\Enums\PackageUnit;
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

    public function test_it_ignores_purchase_limits_before_package_quantities(): void
    {
        $parser = new PackageQuantityParser;

        $skyr = $parser->parse('Note: Maks. 6 | Max. 6 stk. til denne pris. Frit valg. 1 kg. Flere varianter.');
        $soda = $parser->parse('Note: Maks. 12 | 1,5 liter. Flere varianter. MAX. 12 STK TIL DENNE PRIS.');

        $this->assertSame(PackageUnit::Kilogram, $skyr->unit);
        $this->assertSame('1', (string) $skyr->normalizedAmount());

        $this->assertSame(PackageUnit::Liter, $soda->unit);
        $this->assertSame('1.5', (string) $soda->normalizedAmount());
    }

    public function test_it_uses_smallest_total_amount_for_mixed_multipacks(): void
    {
        $quantity = (new PackageQuantityParser)->parse('8x50 cl Monster eller 24x25 cl Coca-Cola. Pr. liter 19.75.');

        $this->assertSame(PackageUnit::Centiliter, $quantity->unit);
        $this->assertSame(CompareUnit::Liter, $quantity->compareUnit());
        $this->assertSame('400', (string) $quantity->amount);
        $this->assertSame('4.000000', (string) $quantity->normalizedAmount());
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
