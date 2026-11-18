<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('academic-calendar:send-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping();

Schedule::command('finance:cancel-exited-invoices')
    ->dailyAt('01:00')
    ->withoutOverlapping();
