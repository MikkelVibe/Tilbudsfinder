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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OfferSearchPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_search_page_with_results_and_filters(): void
    {
        $rema = Grocer::factory()->create(['slug' => 'rema1000', 'name' => 'REMA 1000']);
        $netto = Grocer::factory()->create(['slug' => 'netto', 'name' => 'Netto']);
        $this->offer($rema, 'Arla Letmælk', 12.00);
        $this->offer($netto, 'Kyllingebryst', 35.00);

        $this->get('/tilbud?q=mælk&grocers[]=rema1000&sort=price_asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Offers/Index', false)
                ->where('filters.q', 'mælk')
                ->where('filters.grocers.0', 'rema1000')
                ->where('filters.sort', 'price_asc')
                ->where('results.data.0.title', 'Arla Letmælk')
                ->where('results.meta.total', 1)
                ->has('grocers')
                ->has('sortOptions')
                ->has('quickSearches'));
    }

    public function test_it_groups_trusted_canonical_products_into_one_result(): void
    {
        $canonicalProduct = CanonicalProduct::factory()->create([
            'name' => 'Premier Is Astronaut 6 x 50 ml',
        ]);
        $superbrugsen = Grocer::factory()->create(['slug' => 'superbrugsen', 'name' => 'SuperBrugsen']);
        $kvickly = Grocer::factory()->create(['slug' => 'kvickly', 'name' => 'Kvickly']);
        $daglibrugsen = Grocer::factory()->create(['slug' => 'daglibrugsen', 'name' => "Dagli'Brugsen"]);

        $this->offer($superbrugsen, 'PREMIER IS ASTRONAUT IS 6 X 50 ML', 25.00, canonicalProduct: $canonicalProduct);
        $this->offer($kvickly, 'PREMIER IS ASTRONAUT IS 6 X 50 ML', 25.00, canonicalProduct: $canonicalProduct);
        $cheapest = $this->offer($daglibrugsen, 'PREMIER IS ASTRONAUT IS 6 X 50 ML', 20.00, canonicalProduct: $canonicalProduct);

        $this->get('/tilbud?q=astronaut&sort=price_asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Offers/Index', false)
                ->has('results.data', 1)
                ->where('results.data.0.id', $cheapest->scraped_offer_id)
                ->where('results.data.0.title', 'Premier Is Astronaut 6 x 50 ml')
                ->where('results.data.0.productStoreCount', 3)
                ->where('results.data.0.productOfferCount', 3)
                ->where('results.data.0.price', '20,00')
                ->etc());
    }

    public function test_it_keeps_unmatched_offer_results_separate(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000', 'name' => 'REMA 1000']);

        $this->offer($grocer, 'Vaniljeis A', 20.00);
        $this->offer($grocer, 'Vaniljeis B', 25.00);

        $this->get('/tilbud?q=vaniljeis')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Offers/Index', false)
                ->has('results.data', 2)
                ->etc());
    }

    public function test_it_sorts_canonical_product_results_by_displayed_name(): void
    {
        $grocer = Grocer::factory()->create(['slug' => 'rema1000', 'name' => 'REMA 1000']);
        $apple = CanonicalProduct::factory()->create(['name' => 'Apple Juice']);
        $banana = CanonicalProduct::factory()->create(['name' => 'Banana Juice']);

        $this->offer($grocer, 'ZZZ source title', 20.00, canonicalProduct: $apple);
        $this->offer($grocer, 'AAA source title', 25.00, canonicalProduct: $banana);

        $this->get('/tilbud?sort=name_asc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Offers/Index', false)
                ->where('results.data.0.title', 'Apple Juice')
                ->where('results.data.1.title', 'Banana Juice')
                ->etc());

        $this->get('/tilbud?sort=name_desc')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Offers/Index', false)
                ->where('results.data.0.title', 'Banana Juice')
                ->where('results.data.1.title', 'Apple Juice')
                ->etc());
    }

    private function offer(Grocer $grocer, string $title, float $price, ?CanonicalProduct $canonicalProduct = null): OfferSearchDocument
    {
        $batch = ImportBatch::factory()->for($grocer)->create();
        $paper = Paper::factory()->for($grocer)->for($batch)->create([
            'active_from' => now()->subDay(),
            'active_until' => now()->addWeek(),
        ]);
        $product = GrocerProduct::factory()->for($grocer)->create([
            'name' => $title,
        ]);
        $offer = ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->for($product)->create([
            'title' => $title,
            'price' => $price,
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
