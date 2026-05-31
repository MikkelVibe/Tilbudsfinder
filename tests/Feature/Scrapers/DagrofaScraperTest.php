<?php

namespace Tests\Feature\Scrapers;

use App\Scrapers\Dagrofa\MenyScraper;
use App\Scrapers\Dagrofa\MinKobmandScraper;
use App\Scrapers\Dagrofa\SparScraper;
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
            'longjohnapi.azurewebsites.net/Product/query*' => Http::response([
                'total' => 3,
                'products' => [
                    $this->product('1001', 'Kyllingebryst', '450 g / dansk', 35, 25, 1),
                    $this->product('1002', 'To for sodavand', '2 x 150 cl', 30, 25, 2),
                    $this->product('1003', 'Normal vare', '1 stk', 10, 0, 1),
                ],
            ]),
        ]);

        $payloads = (new SparScraper)->fetchPapers();

        $this->assertCount(1, $payloads);
        $this->assertSame('spar-2026-05-31', $payloads[0]->sourceExternalId);

        $payload = json_decode($payloads[0]->rawPayload, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('dagrofa_longjohn_discount_products', $payload['catalog']['source_strategy']);
        $this->assertSame(1, $payload['catalog']['fetched_offer_count']);
        $this->assertSame('Kyllingebryst', $payload['offers'][0]['productDisplayName']);
    }

    public function test_chain_wrappers_use_expected_keys(): void
    {
        $this->assertSame('meny', (new MenyScraper)->grocerKey());
        $this->assertSame('spar', (new SparScraper)->grocerKey());
        $this->assertSame('minkobmand', (new MinKobmandScraper)->grocerKey());
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
