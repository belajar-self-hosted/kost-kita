<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:expire-no-show')
    ->everyMinute()
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('billing:generate-monthly-invoices')
    ->dailyAt('00:10')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();

Schedule::command('deposits:mark-late-settlements')
    ->dailyAt('00:20')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping();
