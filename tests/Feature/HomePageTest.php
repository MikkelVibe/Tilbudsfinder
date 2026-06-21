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

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The homepage should show the best known product image, matching the detail page fallback chain.
     */
    public function test_homepage_offer_images_use_source_grocer_product_then_canonical_product_precedence(): void
    {
        $grocer = Grocer::factory()->create();
        $batch = ImportBatch::factory()->for($grocer)->create();
        $paper = Paper::factory()->for($grocer)->for($batch, 'importBatch')->create([
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        $canonicalProduct = CanonicalProduct::factory()->create([
            'name' => 'Carlsberg Pilsner',
            'image_url' => 'https://images.example/canonical-carlsberg.webp',
        ]);
        $grocerProduct = GrocerProduct::factory()->for($grocer)->create([
            'image_url' => 'https://images.example/grocer-carlsberg.webp',
        ]);
        $canonicalOffer = ScrapedOffer::factory()
            ->for($grocer)
            ->for($batch, 'importBatch')
            ->for($paper)
            ->create([
                'title' => 'Carlsberg Pilsner 6X33 Cl',
                'image_url' => null,
                'created_at' => now()->subMinutes(2),
            ]);
        $grocerProductOffer = ScrapedOffer::factory()
            ->for($grocer)
            ->for($batch, 'importBatch')
            ->for($paper)
            ->create([
                'title' => 'Carlsberg Pilsner 12X33 Cl',
                'grocer_product_id' => $grocerProduct->id,
                'image_url' => null,
                'created_at' => now()->subMinute(),
            ]);
        $sourceImageOffer = ScrapedOffer::factory()
            ->for($grocer)
            ->for($batch, 'importBatch')
            ->for($paper)
            ->create([
                'title' => 'Carlsberg Pilsner 18X33 Cl',
                'grocer_product_id' => $grocerProduct->id,
                'image_url' => 'https://images.example/source-carlsberg.webp',
                'created_at' => now(),
            ]);

        foreach ([$canonicalOffer, $grocerProductOffer, $sourceImageOffer] as $offer) {
            ProductMatch::factory()
                ->for($offer)
                ->for($canonicalProduct)
                ->create([
                    'match_method' => 'test',
                    'confidence' => 100,
                    'status' => 'matched',
                ]);
        }

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home', false)
                ->where('latestOffers.0.id', $sourceImageOffer->id)
                ->where('latestOffers.0.imageUrl', 'https://images.example/source-carlsberg.webp')
                ->where('latestOffers.1.id', $grocerProductOffer->id)
                ->where('latestOffers.1.imageUrl', 'https://images.example/grocer-carlsberg.webp')
                ->where('latestOffers.2.id', $canonicalOffer->id)
                ->where('latestOffers.2.imageUrl', 'https://images.example/canonical-carlsberg.webp')
                ->etc()
            );
    }

    public function test_homepage_stores_include_search_filter_slug(): void
    {
        $grocer = Grocer::factory()->create([
            'slug' => 'spar',
            'name' => 'SPAR',
            'is_enabled' => true,
        ]);
        Grocer::factory()->create([
            'slug' => 'netto',
            'name' => 'Netto',
            'is_enabled' => true,
        ]);
        Grocer::factory()->create([
            'slug' => 'disabled',
            'name' => 'Disabled',
            'is_enabled' => false,
        ]);
        $batch = ImportBatch::factory()->for($grocer)->create();
        $paper = Paper::factory()->for($grocer)->for($batch, 'importBatch')->create([
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);

        ScrapedOffer::factory()
            ->for($grocer)
            ->for($batch, 'importBatch')
            ->for($paper)
            ->create(['price' => 12.00]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home', false)
                ->where('stores.0.slug', 'spar')
                ->where('stores.0.name', 'SPAR')
                ->where('stores.0.count', '1 tilbud')
                ->where('allStoreSlugs', fn (mixed $slugs): bool => collect($slugs)->contains('netto')
                    && collect($slugs)->contains('spar')
                    && ! collect($slugs)->contains('disabled'))
                ->where('enabledStoreCount', fn (int $count): bool => $count >= 2)
                ->etc()
            );
    }
}
