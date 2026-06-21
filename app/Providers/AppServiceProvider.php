<?php

namespace App\Providers;

use App\Search\DatabaseOfferSearchEngine;
use App\Search\MeilisearchOfferSearchEngine;
use App\Search\OfferSearchEngine;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OfferSearchEngine::class, function (Application $app): OfferSearchEngine {
            return $app->make(match (config('search.driver')) {
                'meilisearch' => MeilisearchOfferSearchEngine::class,
                default => DatabaseOfferSearchEngine::class,
            });
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
