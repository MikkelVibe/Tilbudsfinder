<?php

namespace App\Http\Controllers;

use App\Models\ScrapedOffer;
use App\Popularity\OfferPopularityRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OfferViewTrackingController extends Controller
{
    public function __invoke(ScrapedOffer $scrapedOffer, Request $request, OfferPopularityRecorder $recorder): Response
    {
        $recorder->recordDetailView($scrapedOffer, $request);

        return response()->noContent();
    }
}
