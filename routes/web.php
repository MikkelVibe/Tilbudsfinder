<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\OfferDetailController;
use App\Http\Controllers\OfferSearchPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/tilbud', OfferSearchPageController::class)->name('offers.index');
Route::get('/tilbud/{scrapedOffer}', [OfferDetailController::class, 'show'])->name('offers.show');
