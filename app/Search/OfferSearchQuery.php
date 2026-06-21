<?php

namespace App\Search;

final readonly class OfferSearchQuery
{
    /**
     * @param  list<string>  $grocerSlugs
     */
    public function __construct(
        public ?string $query,
        public array $grocerSlugs,
        public string $sort,
        public int $perPage,
        public ?float $priceMin = null,
        public ?float $priceMax = null,
    ) {}
}
