<?php

namespace Tests\Feature\Api\V1;

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
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OfferSearchApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_searches_active_offers_by_ranked_text(): void
    {
        $rema = Grocer::factory()->create(['slug' => 'rema1000', 'name' => 'REMA 1000']);
        $this->offer($rema, 'Arla Letmælk 1 liter', 12.95, brand: 'Arla', category: 'Mejeri');
        $this->offer($rema, 'Kyllingebryst filet', 39.95, category: 'Kød');

        $response = $this->getJson('/api/v1/offers/search?q=arla mælk');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Arla Letmælk 1 liter')
            ->assertJsonCount(1, 'data');
    }

    public function test_it_filters_by_grocer_slugs(): void
    {
        $rema = Grocer::factory()->create(['slug' => 'rema1000', 'name' => 'REMA 1000']);
        $netto = Grocer::factory()->create(['slug' => 'netto', 'name' => 'Netto']);
        $this->offer($rema, 'Bananer', 15.00);
        $this->offer($netto, 'Bananer øko', 18.00);

        $response = $this->getJson('/api/v1/offers/search?q=bananer&grocers=netto');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grocer.slug', 'netto');
    }

    public function test_it_sorts_browse_results_by_requested_sort(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $this->offer($grocer, 'B produkt', 30.00);
        $this->offer($grocer, 'A produkt', 10.00);

        $this->getJson('/api/v1/offers/search?sort=price_asc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'A produkt');

        $this->getJson('/api/v1/offers/search?sort=price_desc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'B produkt');

        $this->getJson('/api/v1/offers/search?sort=name_asc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'A produkt');

        $this->getJson('/api/v1/offers/search?sort=name_desc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'B produkt');
    }

    public function test_it_sorts_by_unit_price_and_filters_by_price_interval(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $this->offer($grocer, 'Dyr literpris', 20.00, unitPrice: 20.00);
        $this->offer($grocer, 'Billig literpris', 25.00, unitPrice: 10.00);
        $this->offer($grocer, 'For billig totalpris', 5.00, unitPrice: 5.00);

        $this->getJson('/api/v1/offers/search?sort=unit_price_asc&price_min=10&price_max=30')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Billig literpris');
    }

    public function test_it_rejects_inverted_price_ranges(): void
    {
        $this->getJson('/api/v1/offers/search?price_min=50&price_max=10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price_max');
    }

    public function test_it_falls_back_to_grouped_database_search_when_configured_meilisearch_fails(): void
    {
        Config::set('search.driver', 'meilisearch');
        Config::set('search.meilisearch.host', 'http://meili.test');
        Http::preventStrayRequests();
        Http::fake([
            'http://meili.test/indexes/offers/search' => Http::response(['message' => 'Index missing'], 404),
        ]);

        $canonicalProduct = CanonicalProduct::factory()->create([
            'name' => 'Premier Is Astronaut 6 x 50 ml',
        ]);
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $this->offer($grocer, 'PREMIER IS ASTRONAUT IS 6 X 50 ML', 25.00, canonicalProduct: $canonicalProduct);
        $this->offer($grocer, 'PREMIER IS ASTRONAUT IS 6 X 50 ML', 20.00, canonicalProduct: $canonicalProduct);

        $this->getJson('/api/v1/offers/search?q=astronaut&grocers=rema1000&sort=price_asc&price_min=10&price_max=30')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Premier Is Astronaut 6 x 50 ml')
            ->assertJsonPath('data.0.price', '20.00')
            ->assertJsonPath('data.0.product_offer_count', 2);

        Http::assertSentCount(1);
    }

    public function test_it_falls_back_to_grouped_database_search_when_configured_meilisearch_connection_fails(): void
    {
        Config::set('search.driver', 'meilisearch');
        Config::set('search.meilisearch.host', 'http://meili.test');
        Http::preventStrayRequests();
        Http::fake([
            'http://meili.test/indexes/offers/search' => Http::failedConnection(),
        ]);

        $canonicalProduct = CanonicalProduct::factory()->create([
            'name' => 'Premier Is Astronaut 6 x 50 ml',
        ]);
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $this->offer($grocer, 'PREMIER IS ASTRONAUT IS 6 X 50 ML', 25.00, canonicalProduct: $canonicalProduct);
        $this->offer($grocer, 'PREMIER IS ASTRONAUT IS 6 X 50 ML', 20.00, canonicalProduct: $canonicalProduct);

        $this->getJson('/api/v1/offers/search?q=astronaut&grocers=rema1000&sort=price_asc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Premier Is Astronaut 6 x 50 ml')
            ->assertJsonPath('data.0.price', '20.00')
            ->assertJsonPath('data.0.product_offer_count', 2);

        Http::assertSentCount(1);
    }

    public function test_it_excludes_expired_offers(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000']);
        $this->offer($grocer, 'Aktiv mælk', 12.00);
        $this->offer(
            $grocer,
            'Udløbet mælk',
            8.00,
            activeFrom: now()->subWeeks(2),
            activeUntil: now()->subWeek(),
        );

        $response = $this->getJson('/api/v1/offers/search?q=mælk');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Aktiv mælk');
    }

    private function offer(
        Grocer $grocer,
        string $title,
        float $price,
        ?string $brand = null,
        ?string $category = null,
        mixed $activeFrom = null,
        mixed $activeUntil = null,
        ?float $unitPrice = null,
        ?CanonicalProduct $canonicalProduct = null,
    ): OfferSearchDocument {
        $batch = ImportBatch::factory()->for($grocer)->create();
        $paper = Paper::factory()->for($grocer)->for($batch)->create([
            'active_from' => $activeFrom ?? now()->subDay(),
            'active_until' => $activeUntil ?? now()->addWeek(),
        ]);
        $product = GrocerProduct::factory()->for($grocer)->create([
            'name' => $title,
            'brand' => $brand,
            'category' => $category,
        ]);
        $offer = ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->for($product)->create([
            'title' => $title,
            'price' => $price,
            'unit_price' => $unitPrice,
            'compare_unit' => $unitPrice === null ? null : 'l',
            'description' => null,
        ]);

        if ($canonicalProduct !== null) {
            ProductMatch::factory()
                ->for($offer)
                ->for($canonicalProduct)
                ->create([
                    'match_method' => 'test',
                    'confidence' => 100,
                    'status' => 'matched',
                ]);
        }

        return (new OfferSearchDocumentBuilder)->updateForOffer($offer);
    }
}
