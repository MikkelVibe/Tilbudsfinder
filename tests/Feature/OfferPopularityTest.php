<?php

namespace Tests\Feature;

use App\Models\Grocer;
use App\Models\ImportBatch;
use App\Models\Paper;
use App\Models\ScrapedOffer;
use App\Popularity\OfferPopularity;
use App\Popularity\OfferPopularityAggregator;
use App\Popularity\OfferPopularityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfferPopularityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_homepage_orders_popular_offers_by_twenty_four_hour_detail_views(): void
    {
        $leadingOffer = $this->offer('Popular coffee');
        $secondOffer = $this->offer('Runner up pasta');

        foreach (range(1, 3) as $view) {
            $this->event($leadingOffer, 'same-session', now()->subMinutes($view));
        }

        $this->event($secondOffer, 'first-session', now()->subMinutes(3));
        $this->event($secondOffer, 'second-session', now()->subMinutes(2));

        $this->aggregate($leadingOffer);
        $this->aggregate($secondOffer);

        $this->assertDatabaseHas('offer_popularity_scores', [
            'scraped_offer_id' => $leadingOffer->id,
            'window' => OfferPopularity::WINDOW_24_HOURS,
            'score' => 3,
            'unique_sessions' => 1,
            'capped_views' => 3,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Home', false)
                ->where('popularOffers.0.id', $leadingOffer->id)
                ->where('popularOffers.1.id', $secondOffer->id)
                ->etc()
            );
    }

    public function test_homepage_uses_seven_day_behavior_when_twenty_four_hour_behavior_is_empty(): void
    {
        $offer = $this->offer('Earlier week butter');

        $this->event($offer, 'earlier-session', now()->subDays(2));

        $this->aggregate($offer);

        $this->assertDatabaseHas('offer_popularity_scores', [
            'scraped_offer_id' => $offer->id,
            'window' => OfferPopularity::WINDOW_24_HOURS,
            'score' => 0,
        ]);
        $this->assertDatabaseHas('offer_popularity_scores', [
            'scraped_offer_id' => $offer->id,
            'window' => OfferPopularity::WINDOW_7_DAYS,
            'score' => 1,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Home', false)
                ->where('popularOffers.0.id', $offer->id)
                ->etc()
            );
    }

    public function test_refresh_command_recomputes_rolling_scores_as_events_age_out(): void
    {
        $offer = $this->offer('Rolling yoghurt');

        $this->event($offer, 'early-session', now()->subHours(23));
        $this->event($offer, 'fresh-session', now());

        $this->aggregate($offer);

        $this->assertDatabaseHas('offer_popularity_scores', [
            'scraped_offer_id' => $offer->id,
            'window' => OfferPopularity::WINDOW_24_HOURS,
            'score' => 2,
        ]);

        $this->travel(2)->hours();

        $this->artisan('offers:refresh-popularity-scores')
            ->assertExitCode(0);

        $this->assertDatabaseHas('offer_popularity_scores', [
            'scraped_offer_id' => $offer->id,
            'window' => OfferPopularity::WINDOW_24_HOURS,
            'score' => 1,
        ]);
    }

    public function test_refresh_command_bootstraps_scores_from_recent_events(): void
    {
        $offer = $this->offer('Bootstrap skyr');

        $this->event($offer, 'fresh-session', now());

        $this->assertDatabaseCount('offer_popularity_scores', 0);

        $this->artisan('offers:refresh-popularity-scores')
            ->assertExitCode(0);

        $this->assertDatabaseHas('offer_popularity_scores', [
            'scraped_offer_id' => $offer->id,
            'window' => OfferPopularity::WINDOW_24_HOURS,
            'score' => 1,
        ]);
        $this->assertDatabaseHas('offer_popularity_scores', [
            'scraped_offer_id' => $offer->id,
            'window' => OfferPopularity::WINDOW_7_DAYS,
            'score' => 1,
        ]);
    }

    public function test_offer_detail_get_requests_do_not_record_popularity_events(): void
    {
        $activeOffer = $this->offer('Active cereal');

        $this
            ->withHeader('User-Agent', 'Mozilla/5.0 Normal Browser')
            ->get(route('offers.show', $activeOffer))
            ->assertOk();

        $this
            ->withHeader('User-Agent', 'Googlebot/2.1')
            ->get(route('offers.show', $activeOffer))
            ->assertOk();

        $this
            ->withHeaders([
                'Purpose' => 'prefetch',
                'User-Agent' => 'Mozilla/5.0 Normal Browser',
            ])
            ->get(route('offers.show', $activeOffer))
            ->assertOk();

        $this->assertDatabaseCount('offer_popularity_events', 0);
    }

    public function test_offer_view_tracking_endpoint_records_only_eligible_human_views(): void
    {
        $activeOffer = $this->offer('Active cereal');
        $expiredOffer = $this->offer('Expired cereal', now()->subWeeks(2), now()->subWeek());

        $this
            ->withHeader('User-Agent', 'Mozilla/5.0 Normal Browser')
            ->post(route('offers.view', $activeOffer))
            ->assertNoContent();

        $this
            ->withHeader('User-Agent', 'Googlebot/2.1')
            ->post(route('offers.view', $activeOffer))
            ->assertNoContent();

        $this
            ->withHeader('User-Agent', 'Mozilla/5.0 Normal Browser')
            ->post(route('offers.view', $expiredOffer))
            ->assertNoContent();

        $this->assertDatabaseCount('offer_popularity_events', 1);
        $this->assertDatabaseHas('offer_popularity_events', [
            'scraped_offer_id' => $activeOffer->id,
            'event_type' => OfferPopularity::DETAIL_VIEW_EVENT,
            'user_agent_family' => 'other',
            'is_bot' => false,
        ]);
    }

    public function test_offer_view_tracking_endpoint_is_rate_limited(): void
    {
        $offer = $this->offer('Rate limited cereal');

        foreach (range(1, 20) as $attempt) {
            $this
                ->withHeader('User-Agent', "Mozilla/5.0 Browser {$attempt}")
                ->post(route('offers.view', $offer))
                ->assertNoContent();
        }

        $this
            ->withHeader('User-Agent', 'Mozilla/5.0 Browser 21')
            ->post(route('offers.view', $offer))
            ->assertTooManyRequests();
    }

    public function test_offer_detail_page_caps_recorded_views_per_session_per_day(): void
    {
        $offer = $this->offer('Refreshable oats');
        $recorder = app(OfferPopularityRecorder::class);
        $request = $this->requestWithSession('popular-session');
        $sessionHash = hash_hmac('sha256', $request->session()->getId(), (string) config('app.key'));

        foreach (range(1, OfferPopularity::DAILY_SESSION_VIEW_CAP) as $view) {
            DB::table('offer_popularity_events')->insert([
                'scraped_offer_id' => $offer->id,
                'session_hash' => $sessionHash,
                'event_type' => OfferPopularity::DETAIL_VIEW_EVENT,
                'occurred_at' => now()->subMinutes($view),
                'user_agent_family' => 'test',
                'is_bot' => false,
                'created_at' => now()->subMinutes($view),
                'updated_at' => now()->subMinutes($view),
            ]);
        }

        $recorder->recordDetailView($offer, $request);

        $this->assertDatabaseCount('offer_popularity_events', OfferPopularity::DAILY_SESSION_VIEW_CAP);
    }

    private function offer(
        string $title,
        mixed $activeFrom = null,
        mixed $activeUntil = null,
    ): ScrapedOffer {
        $grocer = Grocer::factory()->create();
        $batch = ImportBatch::factory()->for($grocer)->create();
        $paper = Paper::factory()->for($grocer)->for($batch, 'importBatch')->create([
            'active_from' => $activeFrom ?: now()->subDay(),
            'active_until' => $activeUntil ?: now()->addWeek(),
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

    private function event(ScrapedOffer $offer, string $session, Carbon $occurredAt): void
    {
        DB::table('offer_popularity_events')->insert([
            'scraped_offer_id' => $offer->id,
            'session_hash' => hash('sha256', $session),
            'event_type' => OfferPopularity::DETAIL_VIEW_EVENT,
            'occurred_at' => $occurredAt,
            'user_agent_family' => 'test',
            'is_bot' => false,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    private function aggregate(ScrapedOffer $offer): void
    {
        app(OfferPopularityAggregator::class)->aggregate($offer->id);
    }

    private function requestWithSession(string $sessionId): Request
    {
        $request = Request::create('/tilbud/test', 'GET', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Normal Browser',
        ]);
        $session = new Store('test', new ArraySessionHandler(120));
        $session->setId($sessionId);
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }
}
