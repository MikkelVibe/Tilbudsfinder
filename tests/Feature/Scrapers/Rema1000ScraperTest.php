<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\Rema1000\Rema1000Scraper;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Rema1000ScraperTest extends TestCase
{
    public function test_it_reconciles_known_papers_from_paginated_rema_and_tjek_sources_without_algolia(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 10:00:00');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/catalogs')) {
                return Http::response([$this->catalog()]);
            }

            if (str_contains($request->url(), '/offers')) {
                return Http::response([
                    $this->tjekOffer('matched-offer', 'Faxe Kondi', 3, 33, 'cl'),
                    $this->tjekOffer('missing-offer', 'Orkidé', 49, 1, 'pcs'),
                ]);
            }

            if (str_contains($request->url(), '/search/products')) {
                return Http::response([
                    'data' => [$this->product(210617, 'FAXE KONDI', '33 CL. / LEMONADE', 3, 9.09)],
                    'meta' => ['pagination' => ['last_page' => 1, 'total' => 1]],
                ]);
            }

            return null;
        });

        $scraper = new Rema1000Scraper;
        $candidates = $scraper->discoverPapers();
        $payloads = $scraper->fetchPapers($candidates, [
            'paper-week-36' => ['exists' => true],
        ]);

        $this->assertCount(1, $payloads);
        $this->assertFalse($payloads[0]->alreadyFetched);
        $payload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('rema_tjek_offer_match', $payload['catalog']['source_strategy']);
        $this->assertSame(2, $payload['catalog']['fetched_offer_count']);
        $this->assertSame(1, $payload['catalog']['matched_tjek_offer_count']);
        $this->assertSame(1, $payload['catalog']['missing_tjek_offer_count']);
        $this->assertSame('210617', (string) $payload['offers'][0]['rema_product']['id']);
        $this->assertSame('missing_rema_product', $payload['issues'][0]['code']);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'algolia'));
    }

    public function test_product_discovery_follows_every_declared_page(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 10:00:00');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/catalogs')) {
                return Http::response([$this->catalog(1)]);
            }

            if (str_contains($request->url(), '/offers')) {
                return Http::response([$this->tjekOffer('offer-two', 'Second Product', 10, 1, 'kg')]);
            }

            if (str_contains($request->url(), '/search/products')) {
                $page = (int) ($request->data()['page'] ?? 1);

                return Http::response([
                    'data' => [$page === 1
                        ? $this->product(1, 'FIRST PRODUCT', '1 KG.', 5, 5, 'kg')
                        : $this->product(2, 'SECOND PRODUCT', '1 KG.', 10, 10, 'kg')],
                    'meta' => ['pagination' => ['last_page' => 2, 'total' => 2]],
                ]);
            }

            return null;
        });

        $scraper = new Rema1000Scraper;
        $payload = $scraper->fetchPapers($scraper->discoverPapers())[0];
        $decoded = json_decode($payload->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('2', (string) $decoded['offers'][0]['rema_product']['id']);
        Http::assertSentCount(4);
    }

    public function test_it_fails_when_tjek_pagination_does_not_match_declared_offer_count(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 10:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([$this->catalog(2)]),
            'squid-api.tjek.com/v2/offers*' => Http::response([$this->tjekOffer('one', 'Product', 10, 1, 'kg')]),
            'api.digital.rema1000.dk/api/search/products*' => Http::response([
                'data' => [$this->product(1, 'PRODUCT', '1 KG.', 10, 10, 'kg')],
                'meta' => ['pagination' => ['last_page' => 1, 'total' => 1]],
            ]),
        ]);

        $this->expectException(ScraperFetchException::class);
        $this->expectExceptionMessage('declares 2 offers but returned 1');

        $scraper = new Rema1000Scraper;
        $scraper->fetchPapers($scraper->discoverPapers());
    }

    /** @return array<string, mixed> */
    private function catalog(int $offerCount = 2): array
    {
        return [
            'id' => 'paper-week-36',
            'label' => 'Uge 36',
            'run_from' => '2026-08-29T22:00:00+00:00',
            'run_till' => '2026-09-05T21:59:59+00:00',
            'offer_count' => $offerCount,
            'dealer_id' => '11deC',
            'dealer' => ['name' => 'REMA 1000'],
        ];
    }

    /** @return array<string, mixed> */
    private function tjekOffer(string $id, string $heading, int|float $price, int|float $size, string $unit): array
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
    private function product(int $id, string $name, string $underline, int|float $price, int|float $comparePrice, string $compareUnit = 'ltr'): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'underline' => $underline,
            'barcodes' => ["570000000{$id}"],
            'images' => [['large' => "https://images.example/{$id}.webp"]],
            'prices' => [[
                'price' => $price,
                'compare_unit_price' => $comparePrice,
                'compare_unit' => $compareUnit,
                'is_advertised' => true,
                'starting_at' => '2026-08-30T00:00:00+00:00',
                'ending_at' => '2026-09-30T00:00:00+00:00',
            ]],
        ];
    }
}
