<?php

namespace Tests\Feature;

use App\Models\CanonicalProduct;
use App\Models\Grocer;
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
    public function test_homepage_offer_images_fall_back_to_canonical_product_images(): void
    {
        $grocer = Grocer::factory()->create();
        $batch = ImportBatch::factory()->for($grocer)->create();
        $paper = Paper::factory()->for($grocer)->for($batch, 'importBatch')->create([
            'active_from' => now()->subDay(),
            'active_until' => now()->addDay(),
        ]);
        $offer = ScrapedOffer::factory()
            ->for($grocer)
            ->for($batch, 'importBatch')
            ->for($paper)
            ->create([
                'title' => 'Carlsberg Pilsner 6X33 Cl',
                'image_url' => null,
                'created_at' => now(),
            ]);
        $canonicalProduct = CanonicalProduct::factory()->create([
            'name' => 'Carlsberg Pilsner',
            'image_url' => 'https://images.example/carlsberg.webp',
        ]);

        ProductMatch::factory()
            ->for($offer)
            ->for($canonicalProduct)
            ->create([
                'match_method' => 'test',
                'confidence' => 100,
                'status' => 'matched',
            ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Home', false)
                ->where('latestOffers.0.id', $offer->id)
                ->where('latestOffers.0.imageUrl', 'https://images.example/carlsberg.webp')
                ->etc()
            );
    }
}
