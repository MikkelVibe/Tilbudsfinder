<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\OfferDetailController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/tilbud/{scrapedOffer}', [OfferDetailController::class, 'show'])->name('offers.show');
