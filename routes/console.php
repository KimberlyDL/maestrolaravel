<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('duties:update-status')
    ->dailyAt('01:00') // Runs once a day at 1:00 AM server time
    ->withoutOverlapping() // Prevents the command from running if the previous instance is still executing
    ->onOneServer();

// Schedule::command('duties:update-status')
//     ->everyMinute()
//     ->withoutOverlapping() 
//     ->onOneServer();