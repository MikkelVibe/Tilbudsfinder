<?php

namespace App\Search;

use Brick\Math\BigDecimal;

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
        public ?BigDecimal $priceMin = null,
        public ?BigDecimal $priceMax = null,
    ) {}
}
