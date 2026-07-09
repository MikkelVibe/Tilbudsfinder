<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\OfferDetailController;
use App\Http\Controllers\OfferSearchPageController;
use App\Http\Controllers\OfferViewTrackingController;
use App\Http\Controllers\StoreDirectoryController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/butikker', StoreDirectoryController::class)->name('stores.index');
Route::get('/tilbud', OfferSearchPageController::class)->name('offers.index');
Route::post('/tilbud/{scrapedOffer}/view', OfferViewTrackingController::class)
    ->middleware('throttle:offer-views')
    ->name('offers.view');
Route::get('/tilbud/{scrapedOffer}', [OfferDetailController::class, 'show'])->name('offers.show');
