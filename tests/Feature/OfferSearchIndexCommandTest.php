<?php

namespace Tests\Feature;

use App\Models\CanonicalProduct;
use App\Models\Grocer;
use App\Models\GrocerProduct;
use App\Models\ImportBatch;
use App\Models\OfferSearchDocument;
use App\Models\Paper;
use App\Models\ProductMatch;
use App\Models\ScrapedOffer;
use App\Search\OfferSearchDocumentBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfferSearchIndexCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_settings_flushes_and_indexes_documents(): void
    {
        Config::set('search.meilisearch.host', 'http://meili.test');
        Http::preventStrayRequests();
        Http::fake([
            'http://meili.test/*' => Http::response(['taskUid' => 1]),
        ]);

        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $this->offer($grocer, 'Arla Letmælk', 12.00);

        $this->artisan('offers:sync-search-index --settings --flush --chunk=1')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'http://meili.test/indexes/offers/settings'
            && $request['searchableAttributes'][0] === 'canonical_product_name');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://meili.test/indexes/offers/documents');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://meili.test/indexes/offers/documents'
            && $request[0]['title'] === 'Arla Letmælk'
            && $request[0]['grocer_slug'] === 'rema1000');
    }

    public function test_it_treats_existing_meilisearch_index_as_success(): void
    {
        Config::set('search.meilisearch.host', 'http://meili.test');
        Http::preventStrayRequests();
        Http::fake([
            'http://meili.test/indexes' => Http::response([
                'message' => 'Index `offers` already exists.',
                'code' => 'index_already_exists',
            ], 400),
            'http://meili.test/indexes/offers/settings' => Http::response(['taskUid' => 2]),
        ]);

        $this->artisan('offers:sync-search-index --settings --chunk=1')
            ->assertSuccessful();
    }

    public function test_it_rebuilds_search_documents_from_existing_scraped_offers(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $offer = $this->scrapedOffer($grocer, 'PREMIER IS ASTRONAUT IS 6 X 50 ML', 25.00);
        (new OfferSearchDocumentBuilder)->updateForOffer($offer);

        $canonicalProduct = CanonicalProduct::factory()->create([
            'name' => 'Premier Is Astronaut 6 x 50 ml',
        ]);
        ProductMatch::factory()
            ->for($offer)
            ->for($canonicalProduct)
            ->create([
                'match_method' => 'test',
                'confidence' => 100,
                'status' => 'matched',
            ]);

        $this->artisan('offers:rebuild-search-documents --chunk=1')
            ->assertSuccessful();

        $this->assertDatabaseHas('offer_search_documents', [
            'scraped_offer_id' => $offer->id,
            'canonical_product_id' => $canonicalProduct->id,
            'canonical_product_name' => 'Premier Is Astronaut 6 x 50 ml',
            'product_match_confidence' => 100,
        ]);
        $this->assertStringContainsString(
            'Premier Is Astronaut 6 x 50 ml',
            OfferSearchDocument::query()->where('scraped_offer_id', $offer->id)->firstOrFail()->search_text,
        );
    }

    private function offer(Grocer $grocer, string $title, float $price): void
    {
        $offer = $this->scrapedOffer($grocer, $title, $price);

        (new OfferSearchDocumentBuilder)->updateForOffer($offer);
    }

    private function scrapedOffer(Grocer $grocer, string $title, float $price): ScrapedOffer
    {
        $batch = ImportBatch::factory()->for($grocer)->create();
        $paper = Paper::factory()->for($grocer)->for($batch)->create([
            'active_from' => now()->subDay(),
            'active_until' => now()->addWeek(),
        ]);
        $product = GrocerProduct::factory()->for($grocer)->create([
            'name' => $title,
        ]);

        return ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->for($product)->create([
            'title' => $title,
            'price' => $price,
        ]);
    }
}
