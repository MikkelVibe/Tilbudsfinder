<?php

namespace App\Jobs;

use App\Popularity\OfferPopularityAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AggregateOfferPopularity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $scrapedOfferId) {}

    public function handle(OfferPopularityAggregator $aggregator): void
    {
        $aggregator->aggregate($this->scrapedOfferId);
    }
}
