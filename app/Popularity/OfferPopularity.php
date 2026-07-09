<?php

namespace App\Popularity;

use Illuminate\Support\Carbon;

class OfferPopularity
{
    public const DETAIL_VIEW_EVENT = 'detail_view';

    public const WINDOW_24_HOURS = '24h';

    public const WINDOW_7_DAYS = '7d';

    public const DAILY_SESSION_VIEW_CAP = 3;

    public const HOMEPAGE_LIMIT = 3;

    public const HOMEPAGE_CACHE_KEY = 'home:popular-offers:v1';

    /**
     * @return list<string>
     */
    public static function windows(): array
    {
        return [
            self::WINDOW_24_HOURS,
            self::WINDOW_7_DAYS,
        ];
    }

    public static function sinceFor(string $window): Carbon
    {
        return match ($window) {
            self::WINDOW_24_HOURS => now()->subDay(),
            self::WINDOW_7_DAYS => now()->subDays(7),
        };
    }
}
