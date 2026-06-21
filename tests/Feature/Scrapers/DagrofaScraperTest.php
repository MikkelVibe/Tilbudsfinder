<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Dagrofa\MenyScraper;
use App\Scrapers\Dagrofa\MinKobmandScraper;
use App\Scrapers\Dagrofa\SparScraper;
use App\Scrapers\Exceptions\ScraperFetchException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DagrofaScraperTest extends TestCase
{
    public function test_it_fetches_single_item_discounts_from_spar(): void
    {
        CarbonImmutable::setTestNow('2026-05-31 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'ugensavis.spar.dk/' => Http::response($this->ipaperHtml('SPAR uge 2426', 3033533, '351b1b4c-8f34-4c02-8664-bb16f02c3da8', 'Avisen gælder fra fredag 05.06.2026 til og med torsdag 11.06.2026')),
            'longjohnapi.azurewebsites.net/Product/query*' => Http::response([
                'total' => 3,
                'products' => [
                    $this->product('1001', 'Kyllingebryst', '450 g / dansk', 35, 25, 1),
                    $this->product('1002', 'To for sodavand', '2 x 150 cl', 30, 25, 2),
                    $this->product('1003', 'Normal vare', '1 stk', 10, 0, 1),
                ],
            ]),
        ]);

        $scraper = new SparScraper;
        $payloads = $scraper->fetchPapers($scraper->discoverPapers());

        $this->assertCount(1, $payloads);
        $this->assertSame('351b1b4c-8f34-4c02-8664-bb16f02c3da8', $payloads[0]->sourceExternalId);

        $payload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('SPAR uge 2426', $payload['catalog']['label']);
        $this->assertSame('2026-06-05T00:00:00+00:00', $payload['catalog']['run_from']);
        $this->assertSame('2026-06-11T23:59:59+00:00', $payload['catalog']['run_till']);
        $this->assertSame(3033533, $payload['catalog']['ipaper_paper_id']);
        $this->assertSame('https://ugensavis.spar.dk/', $payload['catalog']['source_url']);
        $this->assertSame('dagrofa_longjohn_discount_products', $payload['catalog']['source_strategy']);
        $this->assertSame(1, $payload['catalog']['fetched_offer_count']);
        $this->assertSame('Kyllingebryst', $payload['offers'][0]['productDisplayName']);
    }

    public function test_it_extracts_stable_ipaper_metadata_for_all_dagrofa_chains(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'ugensavis.meny.dk/' => Http::response($this->ipaperHtml('MENY uge 2626', 3036608, 'e0b9b026-1bf3-4a6f-8bdf-6019d48205db', 'Avisen gælder fra fredag 19.06.2026 til og med torsdag 25.06.2026')),
            'ugensavis.spar.dk/' => Http::response($this->ipaperHtml('SPAR uge 2626', 3037035, '70ff60cc-fc5d-4d7a-b67e-5f06d5feaaf4', 'Avisen gælder fra fredag 19.06.2026 til og med torsdag 25.06.2026')),
            'ugensavis.xn--minkbmand-o8a.dk/' => Http::response($this->ipaperHtml('MK uge 2626', 3037015, 'ebb4a0c3-2124-43de-9612-f4dab0797088', 'Avisen gælder fra fredag 19. juni til og med torsdag 25. juni 2026')),
        ]);

        $this->assertDiscoveredPaper(new MenyScraper, 'e0b9b026-1bf3-4a6f-8bdf-6019d48205db', 'MENY uge 2626', 3036608, '2026-06-19T00:00:00+00:00', '2026-06-25T23:59:59+00:00', 'https://ugensavis.meny.dk/');
        $this->assertDiscoveredPaper(new SparScraper, '70ff60cc-fc5d-4d7a-b67e-5f06d5feaaf4', 'SPAR uge 2626', 3037035, '2026-06-19T00:00:00+00:00', '2026-06-25T23:59:59+00:00', 'https://ugensavis.spar.dk/');
        $this->assertDiscoveredPaper(new MinKobmandScraper, 'ebb4a0c3-2124-43de-9612-f4dab0797088', 'MK uge 2626', 3037015, '2026-06-19T00:00:00+00:00', '2026-06-25T23:59:59+00:00', 'https://ugensavis.xn--minkbmand-o8a.dk/');
    }

    public function test_it_rejects_unstable_ipaper_metadata_instead_of_creating_dated_dagrofa_ids(): void
    {
        CarbonImmutable::setTestNow('2026-05-31 12:00:00');
        Http::preventStrayRequests();
        Http::fake([
            'ugensavis.spar.dk/' => Http::response($this->ipaperHtml('SPAR uge 2426', 3033533, '351b1b4c-8f34-4c02-8664-bb16f02c3da8', 'Avisen gælder fra 31.02.2026 til og med 04.03.2026')),
        ]);

        $this->expectException(ScraperFetchException::class);
        $this->expectExceptionMessage('SPAR iPaper metadata did not contain a stable paper UUID and validity dates.');

        (new SparScraper)->discoverPapers();
    }

    public function test_chain_wrappers_use_expected_keys(): void
    {
        $this->assertSame('meny', (new MenyScraper)->grocerKey());
        $this->assertSame('spar', (new SparScraper)->grocerKey());
        $this->assertSame('minkobmand', (new MinKobmandScraper)->grocerKey());
    }

    private function assertDiscoveredPaper(
        MenyScraper|SparScraper|MinKobmandScraper $scraper,
        string $sourceExternalId,
        string $title,
        int $paperId,
        string $activeFrom,
        string $activeUntil,
        string $sourceUrl,
    ): void {
        $payloads = $scraper->discoverPapers();

        $this->assertCount(1, $payloads);
        $this->assertSame($sourceExternalId, $payloads[0]->sourceExternalId);
        $this->assertSame($title, $payloads[0]->title);
        $this->assertSame($sourceExternalId, $payloads[0]->sourcePayload['source_external_id']);
        $this->assertSame($activeFrom, $payloads[0]->sourcePayload['run_from']);
        $this->assertSame($activeUntil, $payloads[0]->sourcePayload['run_till']);
        $this->assertSame($paperId, $payloads[0]->sourcePayload['ipaper_paper_id']);
        $this->assertSame($sourceUrl, $payloads[0]->sourcePayload['source_url']);
    }

    private function ipaperHtml(string $title, int $paperId, string $paperUuid, string $validityText): string
    {
        return <<<HTML
        <script>
            window.staticSettings = {
                paperId: {$paperId},
                name: "{$title}",
                aws: { url: "https://b2-cdn.ipaper.io/iPaper/Papers/{$paperUuid}/" }
            };
        </script>
        <div>{$validityText}</div>
        HTML;
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $sku, string $name, string $summary, int $price, int $discountPrice, int $discountAmount): array
    {
        return [
            'id' => $sku,
            'sku' => $sku,
            'productDisplayName' => $name,
            'summary' => $summary,
            'price' => $price,
            'discountPrice' => $discountPrice,
            'discountAmount' => $discountAmount,
            'highResImg' => "https://images.example/{$sku}.jpg",
            'categoryId' => 12,
            'advertisementProduct' => $discountPrice > 0,
        ];
    }
}
