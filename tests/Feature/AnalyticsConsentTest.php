<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_exposes_the_measurement_id_without_loading_google_analytics_server_side(): void
    {
        config()->set('services.google_analytics.measurement_id', 'G-TJ3MBH96Q0');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('name="google-analytics-id" content="G-TJ3MBH96Q0"', false)
            ->assertDontSee('googletagmanager.com/gtag/js', false);
    }

    public function test_measurement_id_is_not_exposed_when_analytics_is_not_configured(): void
    {
        config()->set('services.google_analytics.measurement_id', null);

        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('google-analytics-id', false);
    }
}
