<?php

use App\Http\Controllers\Api\ScraperAgentController;
use App\Http\Controllers\Api\V1\OfferSearchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function (): void {
    Route::get('offers/search', OfferSearchController::class);
});

Route::prefix('scraper-agent')
    ->middleware('scraper-agent')
    ->group(function (): void {
        Route::get('version', [ScraperAgentController::class, 'version']);
        Route::post('heartbeat', [ScraperAgentController::class, 'heartbeat']);
        Route::post('update-status', [ScraperAgentController::class, 'updateStatus']);
        Route::post('papers/exists', [ScraperAgentController::class, 'knownPapers']);
        Route::post('jobs/claim', [ScraperAgentController::class, 'claimJob']);
        Route::post('jobs/{scrapeJob}/fail', [ScraperAgentController::class, 'failJob']);
        Route::post('jobs/{scrapeJob}/raw-payloads', [ScraperAgentController::class, 'storeRawPayloads']);
    });
