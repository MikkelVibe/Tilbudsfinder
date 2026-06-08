<?php

namespace Tests\Feature;

use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\Paper;
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

    private function offer(string $title, mixed $activeFrom, mixed $activeUntil): ScrapedOffer
    {
        $grocer = Grocer::factory()->create();
        $batch = ImportBatch::factory()->for($grocer)->create();
        $paper = Paper::factory()->for($grocer)->for($batch, 'importBatch')->create([
            'active_from' => $activeFrom,
            'active_until' => $activeUntil,
        ]);

        return ScrapedOffer::factory()
            ->for($grocer)
            ->for($batch, 'importBatch')
            ->for($paper)
            ->create([
                'title' => $title,
                'price' => 25.00,
            ]);
    }
}
