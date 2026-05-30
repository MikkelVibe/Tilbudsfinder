<?php

namespace App\Providers;

use App\Scrapers\Rema1000\Rema1000Scraper;
use App\Scrapers\ScraperRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ScraperRegistry::class, function (Application $app): ScraperRegistry {
            return new ScraperRegistry([
                $app->make(Rema1000Scraper::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
