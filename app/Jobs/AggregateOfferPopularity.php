<?php

namespace App\Jobs;

use App\Popularity\OfferPopularityAggregator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\UniqueFor;

#[UniqueFor(300)]
class AggregateOfferPopularity implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $scrapedOfferId) {}

    public function uniqueId(): string
    {
        return $this->scrapedOfferId;
    }

    public function handle(OfferPopularityAggregator $aggregator): void
    {
        $aggregator->aggregate($this->scrapedOfferId);
    }
}
