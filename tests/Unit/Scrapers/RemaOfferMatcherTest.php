<?php

namespace Tests\Unit\Scrapers;

use App\Scrapers\Rema1000\RemaOfferMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RemaOfferMatcherTest extends TestCase
{
    #[Test]
    public function it_maps_one_grouped_tjek_offer_to_every_confident_rema_variant(): void
    {
        $result = (new RemaOfferMatcher)->match(
            $this->catalog(),
            [$this->offer('offer-soft-drinks', 'Pepsi Max eller Faxe Kondi', 3, 33, 'cl')],
            [
                $this->product(210617, 'FAXE KONDI', '33 CL. / LEMONADE 0 KCAL', 3, 9.09),
                $this->product(211198, 'FAXE KONDI', '33 CL. / 0 KCAL', 3, 9.09),
                $this->product(999999, 'APPELSIN SODAVAND', '33 CL.', 3, 9.09),
            ],
        );

        $this->assertSame(1, $result->matchedTjekOfferCount);
        $this->assertSame(['210617', '211198'], array_map(
            static fn (array $match): string => (string) $match['rema_product']['id'],
            $result->matchedOffers,
        ));
        $this->assertSame(0, $result->ambiguousTjekOfferCount);
        $this->assertSame(0, $result->missingTjekOfferCount);
    }

    #[Test]
    public function it_logs_ambiguous_and_missing_offers_without_fabricating_ids(): void
    {
        $result = (new RemaOfferMatcher)->match(
            $this->catalog(),
            [
                $this->offer('offer-potatoes', 'Kartofler', 10, 1, 'kg'),
                $this->offer('offer-plant', 'Orkidé', 49, 1, 'pcs'),
            ],
            [$this->product(123, 'BAGEKARTOFLER', '1 KG.', 10, 10)],
        );

        $this->assertSame(0, $result->matchedTjekOfferCount);
        $this->assertSame(1, $result->ambiguousTjekOfferCount);
        $this->assertSame(1, $result->missingTjekOfferCount);
        $this->assertSame(['ambiguous_rema_match', 'missing_rema_product'], array_column($result->issues, 'code'));
        $this->assertSame([], $result->matchedOffers);
    }

    #[Test]
    public function it_accepts_long_running_and_weighted_products_without_a_duration_cutoff(): void
    {
        $product = $this->product(404995, 'KYLLINGEBRYSTFILET', 'PR. KG. / DANSK', 69, 69, 'kg');
        $product['is_weight_item'] = true;
        $product['prices'][0]['ending_at'] = '2026-12-31T23:59:59+00:00';

        $result = (new RemaOfferMatcher)->match(
            $this->catalog(),
            [$this->offer('offer-chicken', 'Kyllingebrystfilet', 69, 1, 'kg')],
            [$product],
        );

        $this->assertSame(1, $result->matchedTjekOfferCount);
        $this->assertSame('404995', $result->matchedOffers[0]['rema_product']['id'].'');
        $this->assertSame('2026-12-31T23:59:59+00:00', $result->matchedOffers[0]['advertised_price']['ending_at']);
    }

    #[Test]
    public function it_resolves_one_product_matching_two_tjek_rows_to_the_more_specific_row(): void
    {
        $result = (new RemaOfferMatcher)->match(
            $this->catalog(),
            [
                $this->offer('offer-moist', 'Lotus moist toiletpapir', 20, 1, 'pcs'),
                $this->offer('offer-paper', 'Lotus toiletpapir eller køkkenrulle', 20, 1, 'pcs'),
            ],
            [$this->product(170238, 'TOILETPAPIR', '80 STK. / LOTUS MOIST', 20, 0.25, 'stk')],
        );

        $this->assertSame(1, $result->resolvedConflictCount);
        $this->assertCount(1, $result->matchedOffers);
        $this->assertSame('offer-moist', $result->matchedOffers[0]['tjek_offer']['id']);
        $this->assertSame(1, $result->ambiguousTjekOfferCount);
    }

    #[Test]
    public function it_matches_equivalent_piece_unit_aliases(): void
    {
        $result = (new RemaOfferMatcher)->match(
            $this->catalog(),
            [$this->offer('offer-hokkaido', 'Hokkaido', 15, 1, 'pcs')],
            [$this->product(306668, 'HOKKAIDO', '1 STK.', 15, 15, 'stk')],
        );

        $this->assertSame(1, $result->matchedTjekOfferCount);
        $this->assertSame('306668', (string) $result->matchedOffers[0]['rema_product']['id']);
    }

    #[Test]
    public function it_folds_uppercase_danish_letters_before_matching_titles(): void
    {
        $result = (new RemaOfferMatcher)->match(
            $this->catalog(),
            [$this->offer('offer-kale', 'Grønkål', 10, 500, 'g')],
            [$this->product(306671, 'GRØNKÅL', '500 GR.', 10, 20, 'kg')],
        );

        $this->assertSame(1, $result->matchedTjekOfferCount);
        $this->assertSame('306671', (string) $result->matchedOffers[0]['rema_product']['id']);
    }

    /** @return array<string, mixed> */
    private function catalog(): array
    {
        return ['id' => 'paper-week-36', 'label' => 'Uge 36'];
    }

    /** @return array<string, mixed> */
    private function offer(string $id, string $heading, int|float $price, int|float $size, string $unit): array
    {
        return [
            'id' => $id,
            'catalog_id' => 'paper-week-36',
            'heading' => $heading,
            'description' => '',
            'pricing' => ['price' => $price],
            'quantity' => ['size' => ['from' => $size, 'to' => $size], 'unit' => ['symbol' => $unit]],
            'run_from' => '2026-08-29T22:00:00+00:00',
            'run_till' => '2026-09-05T21:59:59+00:00',
        ];
    }

    /** @return array<string, mixed> */
    private function product(int $id, string $name, string $underline, int|float $price, int|float $compareUnitPrice, string $compareUnit = 'ltr'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'underline' => $underline,
            'barcodes' => ["570000000{$id}"],
            'images' => [['large' => "https://images.example/{$id}.webp"]],
            'prices' => [[
                'price' => $price,
                'compare_unit_price' => $compareUnitPrice,
                'compare_unit' => $compareUnit,
                'is_advertised' => true,
                'starting_at' => '2026-08-30T00:00:00+00:00',
                'ending_at' => '2026-09-30T00:00:00+00:00',
            ]],
        ];
    }
}
