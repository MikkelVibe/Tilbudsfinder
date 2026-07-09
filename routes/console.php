<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('scraper:schedule')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('offers:refresh-popularity-scores')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('offers:prune-popularity-events')
    ->daily()
    ->withoutOverlapping();
