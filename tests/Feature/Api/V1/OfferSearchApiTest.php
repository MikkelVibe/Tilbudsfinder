<?php

namespace Tests\Feature\Api\V1;

use App\Models\Grocer;
use App\Models\GrocerProduct;
use App\Models\ImportBatch;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Search\OfferSearchDocumentBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    ): ScrapedOffer {
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
            'description' => null,
        ]);

        (new OfferSearchDocumentBuilder)->updateForOffer($offer);

        return $offer;
    }
}
