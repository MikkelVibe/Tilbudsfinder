<?php

namespace App\Search;

use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OfferSearch
{
    /**
     * @param  list<string>  $grocerSlugs
     */
    public function search(
        ?string $query,
        array $grocerSlugs,
        string $sort,
        int $perPage,
        ?BigDecimal $priceMin = null,
        ?BigDecimal $priceMax = null,
    ): LengthAwarePaginator {
        $searchQuery = new OfferSearchQuery($query, $grocerSlugs, $sort, $perPage, $priceMin, $priceMax);

        return app(DatabaseOfferSearchEngine::class)->search($searchQuery);
    }
}
