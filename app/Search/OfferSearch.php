<?php

namespace App\Search;

use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class OfferSearch
{
    public function __construct(
        private readonly OfferSearchEngine $engine,
        private readonly DatabaseOfferSearchEngine $database,
    ) {}

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

        try {
            return $this->engine->search($searchQuery);
        } catch (ConnectionException|RuntimeException $exception) {
            if (! config('search.fallback_to_database', true) || $this->engine instanceof DatabaseOfferSearchEngine) {
                throw $exception;
            }

            return $this->database->search($searchQuery);
        }
    }
}
