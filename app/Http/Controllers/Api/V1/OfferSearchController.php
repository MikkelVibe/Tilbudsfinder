<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchOffersRequest;
use App\Http\Resources\Api\V1\OfferSearchResultResource;
use App\Search\OfferSearch;

class OfferSearchController extends Controller
{
    public function __invoke(SearchOffersRequest $request, OfferSearch $search): mixed
    {
        return OfferSearchResultResource::collection($search->search(
            query: $request->queryText(),
            grocerSlugs: $request->grocerSlugs(),
            sort: $request->sort(),
            perPage: $request->perPage(),
            priceMin: $request->priceMin(),
            priceMax: $request->priceMax(),
        ));
    }
}
