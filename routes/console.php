<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cms:retry-media-cleanup')->everyMinute()->withoutOverlapping();
Schedule::command('documents:retry-deliveries')->everyMinute()->withoutOverlapping();
