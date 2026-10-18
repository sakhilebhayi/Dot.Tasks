<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Dot.Tasks scheduled tasks
Schedule::command('tasks:check-due-soon')->dailyAt('07:00');
Schedule::command('tasks:detect-escalation-candidates')->dailyAt('07:30');

// Publish each company's daily aggregate numbers to Dot.Analytics (nothing happens until ANALYTICS_INGEST_URL and
// ANALYTICS_INGEST_SECRET are both set). Quiet hour, UTC, because the snapshot is dated by its UTC day.
Schedule::command('tasks:publish-metrics')->dailyAt('02:10')->timezone('UTC')->withoutOverlapping();
