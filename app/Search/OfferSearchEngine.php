<?php

namespace App\Search;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OfferSearchEngine
{
    public function search(OfferSearchQuery $query): LengthAwarePaginator;
}
