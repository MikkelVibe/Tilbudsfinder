<?php

namespace Tests\Unit\Normalization;

use App\Normalization\DTO\ParsedOfferInput;
use App\Normalization\Enums\CompareUnit;
use App\Normalization\Enums\NormalizationIssueCode;
use App\Normalization\Enums\NormalizedOfferStatus;
use App\Normalization\Enums\PackageUnit;
use App\Normalization\OfferNormalizer;
use PHPUnit\Framework\TestCase;

class OfferNormalizerTest extends TestCase
{
    public function test_it_normalizes_an_unambiguous_weight_offer(): void
    {
        $offer = (new OfferNormalizer)->normalize(new ParsedOfferInput(
            title: '  Arla   smør  ',
            price: '14,95',
            packageText: '500 g',
            sourceUnitPrice: '29,90',
        ));

        $this->assertSame(NormalizedOfferStatus::Succeeded, $offer->status);
        $this->assertSame('Arla smør', $offer->title);
        $this->assertSame('14.95', $offer->price?->decimal());
        $this->assertSame(PackageUnit::Gram, $offer->packageUnit);
        $this->assertSame(CompareUnit::Kilogram, $offer->compareUnit);
        $this->assertSame('0.500000', (string) $offer->normalizedAmount);
        $this->assertSame('29.90', $offer->calculatedUnitPrice?->decimal());
        $this->assertSame('29.90', $offer->unitPrice?->decimal());
        $this->assertSame(100, $offer->confidence);
        $this->assertTrue($offer->isPublishable());
    }

    public function test_it_normalizes_volume_and_count_unit_prices(): void
    {
        $normalizer = new OfferNormalizer;

        $soda = $normalizer->normalize(new ParsedOfferInput(
            title: 'Sodavand',
            price: '12',
            packageText: '1,5 ltr',
        ));
        $apples = $normalizer->normalize(new ParsedOfferInput(
            title: 'Æbler',
            price: '18',
            packageText: '6 stk',
        ));

        $this->assertSame(CompareUnit::Liter, $soda->compareUnit);
        $this->assertSame('8.00', $soda->unitPrice?->decimal());
        $this->assertSame(95, $soda->confidence);

        $this->assertSame(CompareUnit::Piece, $apples->compareUnit);
        $this->assertSame('3.00', $apples->unitPrice?->decimal());
        $this->assertSame(95, $apples->confidence);
    }

    public function test_it_partial_publishes_when_package_cannot_be_parsed(): void
    {
        $offer = (new OfferNormalizer)->normalize(new ParsedOfferInput(
            title: 'Mystery product',
            price: '20',
            packageText: 'ukendt størrelse',
        ));

        $this->assertSame(NormalizedOfferStatus::Partial, $offer->status);
        $this->assertTrue($offer->isPublishable());
        $this->assertNull($offer->unitPrice);
        $this->assertSame(NormalizationIssueCode::UnitUnknown, $offer->issues[0]->code);
    }

    public function test_it_assumes_package_like_units_are_one_stk_with_lower_confidence(): void
    {
        $offer = (new OfferNormalizer)->normalize(new ParsedOfferInput(
            title: 'Tomater',
            price: '25',
            packageText: 'bakke',
        ));

        $this->assertSame(NormalizedOfferStatus::Succeeded, $offer->status);
        $this->assertSame(PackageUnit::Tray, $offer->packageUnit);
        $this->assertSame(CompareUnit::Piece, $offer->compareUnit);
        $this->assertSame('25.00', $offer->unitPrice?->decimal());
        $this->assertSame(60, $offer->confidence);
    }

    public function test_it_rejects_conditional_and_invalid_price_offers(): void
    {
        $normalizer = new OfferNormalizer;

        $conditional = $normalizer->normalize(new ParsedOfferInput(
            title: 'App tilbud',
            price: '10',
            isConditional: true,
        ));
        $invalidPrice = $normalizer->normalize(new ParsedOfferInput(
            title: 'Gratis vare',
            price: '0',
        ));

        $this->assertSame(NormalizedOfferStatus::Rejected, $conditional->status);
        $this->assertSame(NormalizationIssueCode::ConditionalOffer, $conditional->issues[0]->code);
        $this->assertFalse($conditional->isPublishable());

        $this->assertSame(NormalizedOfferStatus::Rejected, $invalidPrice->status);
        $this->assertSame(NormalizationIssueCode::PriceInvalid, $invalidPrice->issues[0]->code);
        $this->assertFalse($invalidPrice->isPublishable());
    }

    public function test_it_warns_and_removes_unit_price_when_source_and_calculated_unit_prices_disagree(): void
    {
        $offer = (new OfferNormalizer)->normalize(new ParsedOfferInput(
            title: 'Smør',
            price: '14,95',
            packageText: '500 g',
            sourceUnitPrice: '19,95',
        ));

        $this->assertSame(NormalizedOfferStatus::Partial, $offer->status);
        $this->assertNull($offer->unitPrice);
        $this->assertSame('29.90', $offer->calculatedUnitPrice?->decimal());
        $this->assertSame('19.95', $offer->sourceUnitPrice?->decimal());
        $this->assertSame(NormalizationIssueCode::UnitPriceMismatch, $offer->issues[0]->code);
        $this->assertSame(49, $offer->confidence);
    }

    public function test_it_uses_smallest_package_amount_for_ranges(): void
    {
        $offer = (new OfferNormalizer)->normalize(new ParsedOfferInput(
            title: 'Shampoo',
            price: '69',
            packageText: '150-300 ml',
        ));

        $this->assertSame(NormalizedOfferStatus::Succeeded, $offer->status);
        $this->assertSame(PackageUnit::Milliliter, $offer->packageUnit);
        $this->assertSame('150', (string) $offer->packageAmount);
        $this->assertSame('0.150000', (string) $offer->normalizedAmount);
        $this->assertSame('460.00', $offer->unitPrice?->decimal());
        $this->assertSame(95, $offer->confidence);
        $this->assertSame([], $offer->issues);
    }

    public function test_it_picks_source_unit_price_matching_compare_unit_from_text(): void
    {
        $offer = (new OfferNormalizer)->normalize(new ParsedOfferInput(
            title: 'Striploin',
            price: '349',
            packageText: 'Pr. stk. 2-3 kg. Kan steges hel. Pr. kg max. 174.50.',
            sourceUnitPriceText: 'Pr. stk. 2-3 kg. Kan steges hel. Pr. kg max. 174.50.',
        ));

        $this->assertSame(NormalizedOfferStatus::Succeeded, $offer->status);
        $this->assertSame('174.50', $offer->sourceUnitPrice?->decimal());
        $this->assertSame('174.50', $offer->calculatedUnitPrice?->decimal());
        $this->assertSame('174.50', $offer->unitPrice?->decimal());
    }
}
