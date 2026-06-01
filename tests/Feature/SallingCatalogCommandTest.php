<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SallingCatalogCommandTest extends TestCase
{
    public function test_it_fetches_bilkatogo_products_and_extracts_eans_from_infos(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'f9vbjlr1bk-dsn.algolia.net/1/indexes/prod_BILKATOGO_PRODUCTS/query' => Http::response([
                'nbHits' => 2,
                'nbPages' => 1,
                'hits' => [
                    $this->bilkaToGoHit('72008', 'Bananer 4 pak øko', '5701331900100'),
                    $this->bilkaToGoHit('41286', 'Agurk', '5711044475956'),
                ],
            ]),
        ]);

        $this->artisan('salling:catalog bilkatogo --limit=2 --json')
            ->expectsOutput('Salling catalog [bilkatogo] fetched.')
            ->expectsOutput('Source: BilkaToGo')
            ->expectsOutput('Index: prod_BILKATOGO_PRODUCTS')
            ->expectsOutput('Total hits: 2')
            ->expectsOutput('Fetched products: 2')
            ->expectsOutput('Products with EAN: 2')
            ->expectsOutput('Unique EANs: 2')
            ->expectsOutput('{"source":"bilkatogo","source_product_id":"72008","title":"Bananer 4 pak øko","brand":null,"package_text":"4 stk","eans":["5701331900100"]}')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-Algolia-Application-Id', 'F9VBJLR1BK')
                && $request->hasHeader('X-Algolia-Api-Key', '1deaf41c87e729779f7695c00f190cc9')
                && $request['attributesToRetrieve'] !== null
                && $request['filters'] === 'nonsearchable:false';
        });
    }

    public function test_it_fetches_foetex_products_and_extracts_eans_from_gtins(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'drp4o45g5t-dsn.algolia.net/1/indexes/prod_FOETEX_PRODUCTS/query' => Http::response([
                'nbHits' => 1,
                'nbPages' => 1,
                'hits' => [
                    [
                        'objectID' => '200361594',
                        'name' => 'Mælkesyrebakterier til voksne 50+',
                        'brand' => 'Salling',
                        'active_gtin' => '5712878470346',
                        'gtins' => ['5712878470346', '5712878470995'],
                        'net_content' => '30',
                        'net_content_unit_of_measure_display' => 'stk',
                    ],
                ],
            ]),
        ]);

        $this->artisan('salling:catalog foetex --json')
            ->expectsOutput('Salling catalog [foetex] fetched.')
            ->expectsOutput('Products with EAN: 1')
            ->expectsOutput('Unique EANs: 2')
            ->expectsOutput('{"source":"foetex","source_product_id":"200361594","title":"Mælkesyrebakterier til voksne 50+","brand":"Salling","package_text":"30 stk","eans":["5712878470346","5712878470995"]}')
            ->assertSuccessful();

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-Algolia-Application-Id', 'DRP4O45G5T')
                && $request->hasHeader('X-Algolia-Api-Key', 'f3a34fc94874579eaf3cd39fef660948')
                && $request['filters'] === 'is_exposed:true';
        });
    }

    public function test_it_rejects_unsupported_salling_catalogs(): void
    {
        Http::fake();

        $this->artisan('salling:catalog netto')
            ->expectsOutput('Salling catalog [netto] is not supported.')
            ->assertFailed();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function bilkaToGoHit(string $id, string $name, string $ean): array
    {
        return [
            'objectID' => $id,
            'name' => $name,
            'brand' => '',
            'netcontent' => '4 stk',
            'infos' => [
                [
                    'code' => 'product_details',
                    'title' => 'Produktdetaljer',
                    'items' => [
                        ['type' => 2, 'title' => 'EAN', 'value' => $ean],
                        ['type' => 2, 'title' => 'PID', 'value' => $id],
                    ],
                ],
            ],
        ];
    }
}
