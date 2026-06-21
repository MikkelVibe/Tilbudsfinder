<?php

namespace Tests\Feature;

use App\Models\CanonicalProduct;
use App\Models\Grocer;
use App\Models\GrocerProduct;
use App\Models\ImportBatch;
use App\Models\Paper;
use App\Models\ProductMatch;
use App\Models\ScrapedOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OfferDetailPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_offer_detail_page_stays_publicly_visible(): void
    {
        $offer = $this->offer('Expired coffee beans', now()->subWeeks(2), now()->subWeek());

        $this->get(route('offers.show', $offer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Offers/Show', false)
                ->where('product.name', 'Expired coffee beans')
                ->etc()
            );
    }

    public function test_similar_offers_only_include_active_offers(): void
    {
        $currentOffer = $this->offer('Organic coffee beans', now()->subDay(), now()->addWeek());
        $activeRecommendation = $this->offer('Coffee beans dark roast', now()->subDay(), now()->addWeek());
        $this->offer('Coffee beans expired roast', now()->subWeeks(2), now()->subWeek());
        $this->offer('Coffee beans future roast', now()->addDay(), now()->addWeek());

        $this->get(route('offers.show', $currentOffer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Offers/Show', false)
                ->has('recommendations', 1)
                ->where('recommendations.0.id', $activeRecommendation->id)
                ->where('recommendations.0.name', 'Coffee beans dark roast')
                ->etc()
            );
    }

    public function test_detail_page_has_short_hero_description_and_full_description(): void
    {
        $longDescription = str_repeat('Fyldig produkttekst med mange detaljer om varen. ', 12);
        $offer = $this->offer('Long product', now()->subDay(), now()->addWeek(), description: $longDescription);

        $this->get(route('offers.show', $offer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Offers/Show', false)
                ->where('product.fullDescription', fn (string $description): bool => str_contains($description, $longDescription))
                ->where('product.description', fn (string $description): bool => mb_strlen($description) < mb_strlen($longDescription))
                ->etc()
            );
    }

    public function test_similar_offers_prefer_same_category_before_loose_title_matches(): void
    {
        $currentOffer = $this->offer(
            'B&J Brookieees & Cream',
            now()->subDay(),
            now()->addWeek(),
            category: 'Is',
            subcategory: 'Bægeris',
        );
        $sameCategory = $this->offer(
            'Hansen Vaniljeis',
            now()->subDay(),
            now()->addWeek(),
            category: 'Is',
            subcategory: 'Bægeris',
        );
        $looseTitleMatch = $this->offer(
            'Brookieees snack cookies',
            now()->subDay(),
            now()->addWeek(),
            category: 'Kiosk',
            subcategory: 'Kiks',
        );

        $this->get(route('offers.show', $currentOffer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Offers/Show', false)
                ->where('recommendations.0.id', $sameCategory->id)
                ->where('recommendations.1.id', $looseTitleMatch->id)
                ->etc()
            );
    }

    public function test_same_product_offers_are_separated_from_similar_offers(): void
    {
        $canonicalProduct = CanonicalProduct::factory()->create(['name' => 'Ben & Jerry Brookieees & Cream']);
        $currentOffer = $this->offer('B&J Brookieees & Cream', now()->subDay(), now()->addWeek(), category: 'Is', canonicalProduct: $canonicalProduct);
        $sameProduct = $this->offer('Ben & Jerry Brookieees & Cream', now()->subDay(), now()->addWeek(), canonicalProduct: $canonicalProduct);
        $sameCategory = $this->offer('Hansen Vaniljeis', now()->subDay(), now()->addWeek(), category: 'Is');

        $this->get(route('offers.show', $currentOffer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Offers/Show', false)
                ->has('currentProductPrices', 2)
                ->where('currentProductPrices.0.price', '25')
                ->where('currentProductPrices.1.price', '25')
                ->where('recommendations.0.id', $sameCategory->id)
                ->etc()
            );
    }

    private function offer(
        string $title,
        mixed $activeFrom,
        mixed $activeUntil,
        ?string $category = null,
        ?string $subcategory = null,
        ?CanonicalProduct $canonicalProduct = null,
        ?string $description = null,
    ): ScrapedOffer {
        $grocer = Grocer::factory()->create();
        $batch = ImportBatch::factory()->for($grocer)->create();
        $paper = Paper::factory()->for($grocer)->for($batch, 'importBatch')->create([
            'active_from' => $activeFrom,
            'active_until' => $activeUntil,
        ]);
        $grocerProduct = GrocerProduct::factory()->for($grocer)->create([
            'name' => $title,
            'category' => $category,
            'subcategory' => $subcategory,
        ]);

        $offer = ScrapedOffer::factory()
            ->for($grocer)
            ->for($batch, 'importBatch')
            ->for($paper)
            ->for($grocerProduct)
            ->create([
                'title' => $title,
                'price' => 25.00,
                'description' => $description,
            ]);

        if ($canonicalProduct !== null) {
            ProductMatch::create([
                'scraped_offer_id' => $offer->id,
                'canonical_product_id' => $canonicalProduct->id,
                'match_method' => 'test',
                'confidence' => 95,
                'status' => 'matched',
            ]);
        }

        return $offer;
    }
}
