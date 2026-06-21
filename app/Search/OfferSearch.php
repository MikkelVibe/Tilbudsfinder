<?php

namespace App\Search;

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
        ?float $priceMin = null,
        ?float $priceMax = null,
    ): LengthAwarePaginator {
        $searchQuery = new OfferSearchQuery($query, $grocerSlugs, $sort, $perPage, $priceMin, $priceMax);

        return app(DatabaseOfferSearchEngine::class)->search($searchQuery);
    }
}
