<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('teachers:subscriptions-expire')->hourly();
Schedule::command('tests:finalize-expired')->everyMinute()->withoutOverlapping();
Schedule::command('announcements:publish-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('phase7f:process-scheduled')->everyMinute()->withoutOverlapping();
