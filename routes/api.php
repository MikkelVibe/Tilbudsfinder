<?php

use App\Http\Controllers\Api\ScraperAgentController;
use Illuminate\Support\Facades\Route;

Route::prefix('scraper-agent')
    ->middleware('scraper-agent')
    ->group(function (): void {
        Route::get('version', [ScraperAgentController::class, 'version']);
        Route::post('heartbeat', [ScraperAgentController::class, 'heartbeat']);
        Route::post('jobs/claim', [ScraperAgentController::class, 'claimJob']);
        Route::post('jobs/{scrapeJob}/fail', [ScraperAgentController::class, 'failJob']);
        Route::post('jobs/{scrapeJob}/raw-payloads', [ScraperAgentController::class, 'storeRawPayloads']);
    });
