<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('recovery:manage purge')->everyMinute()
    ->when(fn () => config('recovery.mode') === 'live')
    ->onFailure(fn () => Log::error('Scheduled backup expiry failed. Operator action required before the retention deadline.'));
