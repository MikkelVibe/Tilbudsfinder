<?php

namespace Tests\Feature;

use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\OfferSearchDocument;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreDirectoryPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_renders_store_directory_with_active_counts_and_highlights(): void
    {
        $rema = Grocer::factory()->create(['slug' => 'test-rema', 'name' => 'Test REMA']);
        $spar = Grocer::factory()->create(['slug' => 'test-spar', 'name' => 'Test SPAR']);
        $bilka = Grocer::factory()->create(['slug' => 'test-bilka', 'name' => 'Test Bilka']);
        Grocer::factory()->create(['slug' => 'test-disabled', 'name' => 'Test Disabled', 'is_enabled' => false]);

        $this->searchDocument($rema, 'Letmælk', 'Mejeri');
        $this->searchDocument($rema, 'Skæreost', 'Mejeri');
        $this->searchDocument($spar, 'Chips', 'Snacks');
        $this->searchDocument($spar, 'Expired chips', 'Snacks', active: false);

        $response = $this->get('/butikker')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stores/Index', false)
                ->has('stores')
                ->has('summary'));

        $stores = collect($response->inertiaProps('stores'));

        $remaStore = $stores->firstWhere('slug', 'test-rema');
        $sparStore = $stores->firstWhere('slug', 'test-spar');
        $bilkaStore = $stores->firstWhere('slug', 'test-bilka');

        $this->assertNotNull($remaStore);
        $this->assertSame(2, $remaStore['offerCount']);
        $this->assertSame('2 aktive tilbud', $remaStore['offerCountLabel']);
        $this->assertSame('/tilbud?grocers=test-rema', $remaStore['href']);
        $this->assertNotNull($remaStore['logoUrl']);

        $this->assertNotNull($sparStore);
        $this->assertSame(1, $sparStore['offerCount']);

        $this->assertNotNull($bilkaStore);
        $this->assertFalse($bilkaStore['isActive']);
        $this->assertNull($stores->firstWhere('slug', 'test-disabled'));
    }

    private function searchDocument(Grocer $grocer, string $title, string $category, bool $active = true): void
    {
        $batch = ImportBatch::factory()->for($grocer)->create();
        $paper = Paper::factory()->for($grocer)->for($batch)->create([
            'active_from' => $active ? now()->subDay() : now()->subWeeks(2),
            'active_until' => $active ? now()->addWeek() : now()->subWeek(),
        ]);
        $offer = ScrapedOffer::factory()->for($grocer)->for($batch)->for($paper)->create([
            'title' => $title,
            'price' => 12.00,
        ]);

        OfferSearchDocument::create([
            'scraped_offer_id' => $offer->id,
            'grocer_id' => $grocer->id,
            'paper_id' => $paper->id,
            'grocer_slug' => $grocer->slug,
            'grocer_name' => $grocer->name,
            'title' => $title,
            'category' => $category,
            'search_text' => $title,
            'price' => 12.00,
            'currency' => 'DKK',
            'active_from' => $paper->active_from,
            'active_until' => $paper->active_until,
        ]);
    }
}
