<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Rema1000\Rema1000Scraper;
use App\Scrapers\Rema1000\RemaAdvertisedProductClient;
use App\Scrapers\Rema1000\RemaTjekClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
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

    public function test_it_preserves_a_failed_tjek_catalog_and_continues_fetching_later_catalogs(): void
    {
        CarbonImmutable::setTestNow('2026-09-01 10:00:00');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/catalogs')) {
                return Http::response([
                    $this->catalog(2, 'paper-failed', 'Uge 36'),
                    $this->catalog(1, 'paper-valid', 'Uge 36 Indstik'),
                ]);
            }

            if (str_contains($request->url(), '/search/products')) {
                return Http::response([
                    'data' => [$this->product(1, 'PRODUCT', '1 KG.', 10, 10, 'kg')],
                    'meta' => ['pagination' => ['last_page' => 1, 'total' => 1]],
                ]);
            }

            if (($request->data()['catalog_id'] ?? null) === 'paper-failed') {
                return Http::response([$this->tjekOffer('failed-offer', 'Product', 10, 1, 'kg', 'paper-failed')]);
            }

            if (($request->data()['catalog_id'] ?? null) === 'paper-valid') {
                return Http::response([$this->tjekOffer('valid-offer', 'Product', 10, 1, 'kg', 'paper-valid')]);
            }

            return null;
        });

        $scraper = new Rema1000Scraper;
        $payloads = $scraper->fetchPapers($scraper->discoverPapers());
        $failed = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);
        $valid = json_decode($payloads[1]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(2, $payloads);
        $this->assertSame('paper-failed', $payloads[0]->sourceExternalId);
        $this->assertSame('rema_tjek_offer_fetch_failed', $failed['catalog']['source_strategy']);
        $this->assertSame(0, $failed['catalog']['fetched_offer_count']);
        $this->assertSame('tjek_catalog_fetch_failed', $failed['issues'][0]['code']);
        $this->assertStringContainsString('declares 2 offers but returned 1', $failed['issues'][0]['message']);
        $this->assertSame('paper-valid', $payloads[1]->sourceExternalId);
        $this->assertSame('1', (string) $valid['offers'][0]['rema_product']['id']);
        $this->assertCount(1, Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), '/search/products'),
        ));
    }

    public function test_rema_product_client_does_not_retry_client_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.digital.rema1000.dk/api/search/products*' => Http::response([], 404),
        ]);

        try {
            (new RemaAdvertisedProductClient)->fetch();
            $this->fail('Expected the REMA product request to fail.');
        } catch (RequestException $exception) {
            $this->assertSame(404, $exception->response->status());
        }

        Http::assertSentCount(1);
    }

    public function test_rema_tjek_client_does_not_retry_client_errors(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/offers*' => Http::response([], 404),
        ]);

        try {
            (new RemaTjekClient)->offers($this->catalog());
            $this->fail('Expected the Tjek offer request to fail.');
        } catch (RequestException $exception) {
            $this->assertSame(404, $exception->response->status());
        }

        Http::assertSentCount(1);
    }

    /** @return array<string, mixed> */
    private function catalog(int $offerCount = 2, string $id = 'paper-week-36', string $label = 'Uge 36'): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'run_from' => '2026-08-29T22:00:00+00:00',
            'run_till' => '2026-09-05T21:59:59+00:00',
            'offer_count' => $offerCount,
            'dealer_id' => '11deC',
            'dealer' => ['name' => 'REMA 1000'],
        ];
    }

    /** @return array<string, mixed> */
    private function tjekOffer(string $id, string $heading, int|float $price, int|float $size, string $unit, string $catalogId = 'paper-week-36'): array
    {
        return [
            'id' => $id,
            'catalog_id' => $catalogId,
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
