<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Exceptions\ScraperFetchException;
use App\Scrapers\Netto\NettoScraper;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NettoScraperTest extends TestCase
{
    public function test_it_fetches_weekly_netto_catalogs_with_tjek_offers(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('weekly-paper', 'Uge 23', 12),
                $this->catalog('price-shock', 'PRIS CHOK på masser af hverdagsfavoritter', 12),
                $this->catalog('expired-paper', 'Uge 22', 12, '2026-05-20T22:00:00+0000', '2026-05-27T21:59:59+0000'),
            ]),
            'squid-api.tjek.com/v2/offers?catalog_id=weekly-paper&offset=0&limit=100' => Http::response(array_map(fn (int $number): array => $this->offer($number), range(1, 12))),
        ]);

        $payloads = (new NettoScraper)->fetchPapers();

        $this->assertCount(1, $payloads);
        $this->assertSame('weekly-paper', $payloads[0]->sourceExternalId);
        $this->assertSame('Uge 23', $payloads[0]->title);

        $payload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('tjek_weekly_catalog_offers', $payload['catalog']['source_strategy']);
        $this->assertSame(12, $payload['catalog']['fetched_offer_count']);
        $this->assertSame(0, $payload['catalog']['offer_count_mismatch']);
        $this->assertSame('Product 1', $payload['offers'][0]['heading']);
    }

    public function test_it_fails_when_no_weekly_catalog_is_active(): void
    {
        CarbonImmutable::setTestNow('2026-06-01 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'squid-api.tjek.com/v2/catalogs*' => Http::response([
                $this->catalog('price-shock', 'PRIS CHOK på masser af hverdagsfavoritter', 12),
            ]),
        ]);

        $this->expectException(ScraperFetchException::class);
        $this->expectExceptionMessage('Netto found no active Uge catalogs.');

        (new NettoScraper)->fetchPapers();
    }

    /**
     * @return array<string, mixed>
     */
    private function catalog(string $id, string $label, int $offerCount, string $runFrom = '2026-05-29T22:00:00+0000', string $runTill = '2026-06-05T21:59:59+0000'): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'run_from' => $runFrom,
            'run_till' => $runTill,
            'offer_count' => $offerCount,
            'page_count' => 36,
            'dealer_id' => '9ba51',
            'dealer' => ['name' => 'Netto'],
            'pdf_url' => "https://squid-api.tjek.com/v2/catalogs/{$id}/download",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function offer(int $number): array
    {
        return [
            'id' => "offer-{$number}",
            'heading' => "Product {$number}",
            'description' => '200 g. Pr. kg 50,00.',
            'catalog_page' => $number,
            'pricing' => ['price' => 10, 'currency' => 'DKK'],
            'quantity' => [
                'unit' => ['symbol' => 'g'],
                'size' => ['from' => 200, 'to' => 200],
                'pieces' => ['from' => 1, 'to' => 1],
            ],
            'images' => ['zoom' => "https://images.example/netto/{$number}.webp"],
            'catalog_id' => 'weekly-paper',
        ];
    }
}
